"""Main application for meme search service."""

import logging
from flask import Flask, request, jsonify
from PIL import Image
import requests
from typing import Dict, List, Optional

from config import DEFAULT_SEARCH_LIMIT
from models.encoder import MultimodalEncoder
from models.video_processor import extract_video_frames
from database.redis_client import RedisClient

# Initialize logging
logging.basicConfig(level=logging.INFO)

# Initialize components
app = Flask(__name__)
encoder = MultimodalEncoder()
redis_client = RedisClient()

def index_meme(
    meme_id: str,
    content_url: str,
    meme_type: str,
    text_content: str = ""
) -> bool:
    """Index a meme in Redis with vector search.
    
    Args:
        meme_id: Unique identifier for the meme
        content_url: URL of the meme content
        meme_type: Type of meme (image/video)
        text_content: Text content in the meme
        
    Returns:
        True if indexing successful, False otherwise
    """
    try:
        # Download content
        response = requests.get(content_url)
        response.raise_for_status()
        image = Image.open(requests.get(content_url, stream=True).raw)
        
        if meme_type == "video":
            # Extract keyframes
            frames = extract_video_frames(content_url)
            if not frames:
                logging.error(f"Failed to extract frames from video: {content_url}")
                return False
            
            # Generate embeddings for each keyframe
            embeddings = []
            emotions = []
            for frame in frames:
                embedding = encoder.encode_image(frame)
                emotion = encoder.analyze_emotions(frame)
                embeddings.append(embedding)
                emotions.append(emotion)
            
            # Average the embeddings
            final_embedding = np.mean(embeddings, axis=0)
            
            # Combine emotions with weights based on confidence
            final_emotions = {}
            for emotion_dict in emotions:
                for emotion, score in emotion_dict.items():
                    final_emotions[emotion] = final_emotions.get(emotion, 0) + score
            
            # Normalize emotion scores
            total = sum(final_emotions.values())
            if total > 0:
                final_emotions = {k: v/total for k, v in final_emotions.items()}
            
        else:  # image
            final_embedding = encoder.encode_image(image)
            final_emotions = encoder.analyze_emotions(image)
        
        # Store in Redis
        meme_data = {
            "embedding": final_embedding.tobytes(),
            "type": meme_type,
            "text_content": text_content,
            "emotions": json.dumps(final_emotions)
        }
        
        return redis_client.store_meme(meme_id, meme_data)
        
    except Exception as e:
        logging.error(f"Error indexing meme {meme_id}: {str(e)}")
        return False

def search_memes(query: str, limit: int = DEFAULT_SEARCH_LIMIT) -> List[Dict]:
    """Search for memes using vector similarity and query expansion.
    
    Args:
        query: Search query
        limit: Maximum number of results to return
        
    Returns:
        List of matching memes with scores
    """
    try:
        # Generate query embedding
        query_embedding = encoder.encode_text(query)
        
        # Expand query
        expanded_queries = encoder.expand_query(query)
        
        # Search in Redis
        return redis_client.search_memes(
            query_embedding,
            expanded_queries,
            limit
        )
        
    except Exception as e:
        logging.error(f"Error searching memes: {str(e)}")
        return []

@app.route("/index", methods=["POST"])
def index():
    """Index a new meme."""
    try:
        data = request.json
        meme_id = data.get("id")
        content_url = data.get("url")
        meme_type = data.get("type", "image")
        text_content = data.get("text_content", "")
        
        if not all([meme_id, content_url]):
            return jsonify({"error": "Missing required fields"}), 400
        
        success = index_meme(meme_id, content_url, meme_type, text_content)
        if success:
            return jsonify({"status": "success"}), 200
        else:
            return jsonify({"error": "Indexing failed"}), 500
            
    except Exception as e:
        return jsonify({"error": str(e)}), 500

@app.route("/search", methods=["GET"])
def search():
    """Search for memes."""
    try:
        query = request.args.get("q")
        limit = int(request.args.get("limit", DEFAULT_SEARCH_LIMIT))
        
        if not query:
            return jsonify({"error": "Missing query parameter"}), 400
        
        results = search_memes(query, limit)
        return jsonify({"results": results}), 200
        
    except Exception as e:
        return jsonify({"error": str(e)}), 500

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)
