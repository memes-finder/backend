from flask import Flask, request, jsonify
from sentence_transformers import SentenceTransformer
import redis
import torch
import numpy as np
from typing import List, Dict
import faiss
import pickle
import json
from concurrent.futures import ThreadPoolExecutor
import logging
import config

app = Flask(__name__)

# Initialize logging
logging.basicConfig(level=logging.INFO, 
                   format='%(asctime)s - %(name)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# Initialize model with optimizations
device = config.DEVICE
logger.info(f"Using device: {device}")

model = SentenceTransformer(config.STEMMER_MODEL)
model.to(device)

if device == 'cuda':
    model.half()  # Convert to FP16 if using GPU
    logger.info("Using half precision (FP16)")

# Initialize Redis connection
redis_client = redis.Redis(
    host=config.REDIS_HOST, 
    port=config.REDIS_PORT, 
    db=config.REDIS_DB,
    password=config.REDIS_PASSWORD,
    ssl=config.REDIS_SSL,
    decode_responses=False
)

# Initialize FAISS index
embedding_dim = model.get_sentence_embedding_dimension()
index = faiss.IndexFlatIP(embedding_dim)  # Inner product index for normalized vectors

def get_embedding(text: str) -> np.ndarray:
    """Generate embedding for a text string with caching"""
    cache_key = f"embedding:{text}"
    cached = redis_client.get(cache_key)
    
    if cached:
        return np.frombuffer(cached, dtype=np.float32)
    
    with torch.no_grad():
        embedding = model.encode(
            text,
            convert_to_tensor=True,
            normalize_embeddings=True,
            show_progress_bar=False
        )
        embedding_np = embedding.cpu().numpy()
        
        # Cache the embedding
        redis_client.set(cache_key, embedding_np.tobytes())
        redis_client.expire(cache_key, config.CACHE_TTL)
        
        return embedding_np

def batch_get_embeddings(texts: List[str]) -> Dict[str, np.ndarray]:
    """Get embeddings for multiple texts at once, utilizing GPU batching"""
    # Check cache first
    embeddings = {}
    texts_to_encode = []
    text_positions = []
    
    for i, text in enumerate(texts):
        cache_key = f"embedding:{text}"
        cached = redis_client.get(cache_key)
        if cached:
            embeddings[text] = np.frombuffer(cached, dtype=np.float32)
        else:
            texts_to_encode.append(text)
            text_positions.append(i)
    
    if texts_to_encode:
        # Process in smaller batches to manage memory
        batch_size = config.BATCH_SIZE  # Reduced from 32
        for i in range(0, len(texts_to_encode), batch_size):
            batch = texts_to_encode[i:i+batch_size]
            
            # Clear CUDA cache before processing
            if device == 'cuda':
                torch.cuda.empty_cache()
            
            # Generate embeddings in one GPU batch
            with torch.no_grad():
                batch_embeddings = model.encode(
                    batch,
                    convert_to_tensor=True,
                    normalize_embeddings=True,
                    show_progress_bar=False,
                    batch_size=batch_size
                )
                batch_np = batch_embeddings.cpu().numpy()
                
                # Cache embeddings
                for text, embedding in zip(batch, batch_np):
                    cache_key = f"embedding:{text}"
                    redis_client.set(cache_key, embedding.tobytes())
                    redis_client.expire(cache_key, config.CACHE_TTL)  # Cache for 24 hours
                    embeddings[text] = embedding
                
                # Clear memory
                del batch_embeddings
                del batch_np
                if device == 'cuda':
                    torch.cuda.empty_cache()
    
    return embeddings

def update_index():
    """Update FAISS index with all tag embeddings from Redis"""
    embeddings = []
    meme_tag_ids = []
    skipped = 0
    
    # Get all tag embeddings from Redis
    for key in redis_client.scan_iter("tag_embedding:*"):
        embedding_data = redis_client.get(key)
        if embedding_data:
            try:
                # Try to load as binary data first
                embedding = np.frombuffer(embedding_data, dtype=np.float32)
                
                # Verify embedding dimension
                if embedding.size != embedding_dim:
                    logger.warning(f"Warning: Embedding dimension mismatch for key {key}: expected {embedding_dim}, got {embedding.size}")
                    skipped += 1
                    continue
                    
            except ValueError:
                # If that fails, try loading as JSON
                try:
                    tag_data = json.loads(embedding_data)
                    embedding = np.array(tag_data['embedding'], dtype=np.float32)
                    
                    # Verify embedding dimension
                    if embedding.size != embedding_dim:
                        logger.warning(f"Warning: Embedding dimension mismatch for key {key}: expected {embedding_dim}, got {embedding.size}")
                        skipped += 1
                        continue
                        
                except (json.JSONDecodeError, KeyError):
                    logger.warning(f"Warning: Could not parse embedding data for key {key}")
                    skipped += 1
                    continue
            
            # Extract meme_id and tag from key
            _, meme_id, tag = key.decode().split(":")
            embeddings.append(embedding)
            meme_tag_ids.append((int(meme_id), tag))
    
    if skipped > 0:
        logger.warning(f"Skipped {skipped} embeddings due to parsing errors or dimension mismatch")
    
    if embeddings:
        logger.info(f"Processing {len(embeddings)} embeddings")
        
        # Convert to numpy array and normalize
        embeddings_array = np.vstack(embeddings)
        faiss.normalize_L2(embeddings_array)
        
        # Update index
        index.reset()
        index.add(embeddings_array)
        
        # Store meme_tag_ids mapping
        redis_client.set('meme_tag_ids_mapping', pickle.dumps(meme_tag_ids))
        logger.info(f"Successfully updated index with {len(embeddings)} embeddings")
    else:
        logger.info("No valid embeddings found to update index")

@app.route('/index-tag', methods=['POST'])
def index_tag():
    data = request.get_json()
    if not data or 'tag' not in data or 'meme_id' not in data:
        return jsonify({'error': 'Missing tag or meme_id'}), 400
    
    tag = data['tag']
    meme_id = data['meme_id']
    
    try:
        # Get embedding for the tag
        embedding = get_embedding(tag)
        
        # Store in Redis
        redis_key = f"tag_embedding:{meme_id}:{tag}"
        redis_client.set(redis_key, embedding.tobytes())
        
        # Update search index
        update_index()
        
        return jsonify({'status': 'success'}), 200
    except Exception as e:
        return jsonify({'error': str(e)}), 500

@app.route('/semantic-search', methods=['POST'])
def semantic_search():
    data = request.get_json()
    if not data or 'query' not in data:
        return jsonify({'error': 'Missing query'}), 400
    
    query = data['query']
    min_similarity = float(data.get('min_similarity', 0.3))
    limit = int(data.get('limit', 20))
    
    try:
        # Get query embedding
        query_embedding = get_embedding(query)
        query_embedding = query_embedding.reshape(1, -1)
        faiss.normalize_L2(query_embedding)
        
        # Search in FAISS index
        similarities, indices = index.search(query_embedding, limit * 2)  # Get more results for filtering
        
        # Get meme_tag_ids mapping
        mapping_data = redis_client.get('meme_tag_ids_mapping')
        if mapping_data is None:
            # If mapping is missing, update the index
            update_index()
            mapping_data = redis_client.get('meme_tag_ids_mapping')
            if mapping_data is None:
                return jsonify({'error': 'No tag embeddings found'}), 500
        
        meme_tag_ids = pickle.loads(mapping_data)
        
        # Filter and format results
        results = []
        seen_memes = set()
        
        for sim, idx in zip(similarities[0], indices[0]):
            if sim < min_similarity:
                continue
                
            if idx >= len(meme_tag_ids):
                continue
                
            meme_id, tag = meme_tag_ids[idx]
            
            if meme_id not in seen_memes:
                results.append({
                    'meme_id': meme_id,
                    'similarity': float(sim),
                    'matching_tag': tag
                })
                seen_memes.add(meme_id)
            
            if len(results) >= limit:
                break
        
        return jsonify({'results': results}), 200
    except Exception as e:
        return jsonify({'error': str(e)}), 500

@app.route('/stem', methods=['POST'])
def stem():
    data = request.get_json()
    if not data or 'text' not in data:
        return jsonify({'error': 'Missing text parameter'}), 400
    
    text = data['text']
    try:
        embedding = get_embedding(text)
        return jsonify({
            'embedding': embedding.tolist()
        }), 200
    except Exception as e:
        return jsonify({'error': str(e)}), 500

@app.route('/batch-index-tags', methods=['POST'])
def batch_index_tags():
    data = request.get_json()
    if not data or 'meme_tags' not in data:
        return jsonify({'error': 'Missing meme_tags'}), 400
    
    meme_tags = data['meme_tags']
    if not isinstance(meme_tags, list):
        return jsonify({'error': 'meme_tags must be a list'}), 400
    
    try:
        # Process tags in smaller batches
        batch_size = config.BATCH_SIZE  # Process 100 memes at a time
        for i in range(0, len(meme_tags), batch_size):
            batch = meme_tags[i:i+batch_size]
            
            # Collect all unique tags from this batch
            all_tags = list(set(
                tag
                for item in batch
                for tag in item['tags']
            ))
            
            # Get embeddings for all tags at once
            tag_embeddings = batch_get_embeddings(all_tags)
            
            # Store embeddings in Redis
            for item in batch:
                meme_id = item['meme_id']
                for tag in item['tags']:
                    if tag in tag_embeddings:
                        redis_key = f"tag_embedding:{meme_id}:{tag}"
                        redis_client.set(redis_key, tag_embeddings[tag].tobytes())
            
            # Clear memory
            del tag_embeddings
            if device == 'cuda':
                torch.cuda.empty_cache()
        
        # Update search index after all batches are processed
        update_index()
        
        return jsonify({'status': 'success'}), 200
    except Exception as e:
        return jsonify({'error': str(e)}), 500

@app.route('/batch-embeddings', methods=['POST'])
def batch_embeddings():
    """Generate embeddings for multiple texts at once"""
    data = request.get_json()
    if not data or 'texts' not in data:
        return jsonify({'error': 'Missing texts'}), 400
    
    texts = data['texts']
    if not isinstance(texts, list):
        return jsonify({'error': 'texts must be a list'}), 400
    
    try:
        # Get embeddings for all texts at once
        embeddings = batch_get_embeddings(texts)
        
        # Convert to lists for JSON serialization
        embeddings_list = [
            embeddings[text].tolist() if text in embeddings else None
            for text in texts
        ]
        
        return jsonify({'embeddings': embeddings_list}), 200
    except Exception as e:
        return jsonify({'error': str(e)}), 500

if __name__ == '__main__':
    # Initialize index on startup
    update_index()
    app.run(host=config.SERVER_HOST, port=config.STEMMER_PORT)
