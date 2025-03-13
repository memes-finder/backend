import torch
# Apply monkey patch for huggingface_hub before importing sentence_transformers
import huggingface_hub
import importlib.util
import sys

# Add backward compatibility for cached_download
if not hasattr(huggingface_hub, 'cached_download'):
    def cached_download(url, *args, **kwargs):
        try:
            # Extract repo_id and filename from url
            if "huggingface.co" in url:
                parts = url.split("/")
                repo_idx = parts.index("huggingface.co") + 1
                if repo_idx < len(parts):
                    repo_id = "/".join(parts[repo_idx:repo_idx+2])
                    filename = parts[-1]
                    cache_dir = kwargs.get("cache_dir", None)
                    return huggingface_hub.hf_hub_download(repo_id=repo_id, filename=filename, cache_dir=cache_dir)
            # Fallback for non-HF URLs
            return url
        except Exception as e:
            print(f"Error in cached_download: {e}")
            return url
    
    # Add cached_download to huggingface_hub module
    huggingface_hub.cached_download = cached_download
    print("Added cached_download compatibility function to huggingface_hub")

from transformers import AutoTokenizer, AutoProcessor, CLIPProcessor, CLIPModel
# Now import sentence_transformers after the patch
from sentence_transformers import SentenceTransformer
import numpy as np
from PIL import Image
import cv2
import redis
from redis.commands.search.field import VectorField, TagField, TextField
from redis.commands.search.query import Query
from redis.commands.search.indexDefinition import IndexDefinition
import m3u8
import tempfile
import subprocess
import os
import logging
from typing import List, Dict, Union, Optional
import requests
from urllib.parse import urlparse, urljoin
import gc
from contextlib import nullcontext
from deepface import DeepFace
import json
import config
import glob

# Initialize logging
logging.basicConfig(level=logging.INFO, 
                    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# Constants imported from config
DEVICE = config.DEVICE
EMBEDDING_DIM = config.EMBEDDING_DIM
BATCH_SIZE = config.BATCH_SIZE
CACHE_TTL = config.CACHE_TTL

# Initialize models
class MultimodalEncoder:
    def __init__(self):
        # Initialize CLIP for visual understanding (instead of LLaVA to avoid flash-attention issues)
        print("Loading image encoder model (CLIP)...")
        self.image_model = CLIPModel.from_pretrained(config.CLIP_MODEL)
        self.image_processor = CLIPProcessor.from_pretrained(config.CLIP_MODEL)
        
        # Initialize Arabic-supported text encoder (multilingual E5)
        print("Loading text encoder model (Sentence Transformer)...")
        self.text_encoder = SentenceTransformer(config.TEXT_MODEL)
        
        # Initialize emotion analyzer
        self.emotion_analyzer = DeepFace
        
        # Move models to GPU if available
        self.image_model.to(DEVICE)
        self.text_encoder.to(DEVICE)
        print("All models loaded successfully")
        
        # Set models to eval mode
        self.image_model.eval()
        
    @torch.no_grad()
    def encode_image(self, image: Image.Image) -> np.ndarray:
        """Generate embeddings for an image using CLIP"""
        try:
            # Process image
            inputs = self.image_processor(images=image, return_tensors="pt").to(DEVICE)
            
            # Get embeddings
            outputs = self.image_model.get_image_features(**inputs)
            embedding = outputs.detach().cpu().numpy().mean(axis=1)  # Average pooling
            
            # Normalize
            embedding = embedding / np.linalg.norm(embedding)
            return embedding.astype(np.float32)
        except Exception as e:
            logging.error(f"Error encoding image: {str(e)}")
            return np.zeros(EMBEDDING_DIM, dtype=np.float32)

    def encode_text(self, text: str) -> np.ndarray:
        """Generate embeddings for text using multilingual E5"""
        try:
            # Add prefix for better search relevance
            search_text = f"query: {text}"
            embedding = self.text_encoder.encode(search_text, convert_to_numpy=True)
            
            # Normalize
            embedding = embedding / np.linalg.norm(embedding)
            return embedding.astype(np.float32)
        except Exception as e:
            logging.error(f"Error encoding text: {str(e)}")
            return np.zeros(EMBEDDING_DIM, dtype=np.float32)
    
    def analyze_emotions(self, image: Image.Image) -> Dict:
        """Analyze emotions in an image using DeepFace"""
        try:
            # Convert PIL to numpy array
            img_array = np.array(image)
            
            # Analyze emotions
            analysis = self.emotion_analyzer.analyze(img_array, 
                                                   actions=['emotion'],
                                                   enforce_detection=False)
            
            return analysis[0]['emotion'] if analysis else {}
        except Exception as e:
            logging.error(f"Error analyzing emotions: {str(e)}")
            return {}

    def expand_query(self, query: str) -> List[str]:
        """Expand query with Arabic support"""
        expanded_queries = [query]
        
        # Add Arabic-specific expansions
        if any(ord(c) in range(0x0600, 0x06FF) for c in query):  # If contains Arabic
            # Add common Arabic meme-related terms
            expanded_queries.extend([
                f"{query} ميم",
                f"{query} مضحك",
                f"{query} تحشيش"
            ])
        
        return expanded_queries

# Initialize Redis with vector search
def init_redis():
    client = redis.Redis(
        host=config.REDIS_HOST, 
        port=config.REDIS_PORT, 
        db=config.REDIS_SEARCH_DB,
        password=config.REDIS_PASSWORD,
        decode_responses=False
    )
    
    # Create search index for memes
    try:
        schema = (
            VectorField("embedding", "HNSW", {"TYPE": "FLOAT32", "DIM": EMBEDDING_DIM, "DISTANCE_METRIC": "COSINE"}),
            TagField("type"),  # image or video
            TextField("text_content"),  # Any text content in the meme
            TextField("emotions")  # Emotion analysis results
        )
        
        client.ft(config.REDIS_INDEX_NAME).create_index(schema)
    except Exception as e:
        logger.warning(f"Index might already exist: {str(e)}")
    
    return client

# Initialize global instances
encoder = MultimodalEncoder()
redis_client = init_redis()

from flask import Flask, request, jsonify
import torch
from PIL import Image
import numpy as np
from typing import List, Dict, Union, Tuple
import requests
from io import BytesIO
import logging
import json
import m3u8
import cv2
import os
import tempfile
import subprocess
from redis.commands.search.query import Query
import gc
from contextlib import nullcontext
from urllib.parse import urlparse, urljoin

app = Flask(__name__)
logging.basicConfig(level=logging.INFO)

# Initialize models with optimizations
device = config.DEVICE
logging.info(f"Using device: {device}")

# We're using the models already loaded in MultimodalEncoder
# No need to load CLIP model again here

# Initialize Redis connection with connection pooling
redis_pool = redis.ConnectionPool(
    host=config.REDIS_HOST, 
    port=config.REDIS_PORT, 
    db=config.REDIS_SEARCH_DB,
    password=config.REDIS_PASSWORD,
    decode_responses=False,
    max_connections=config.REDIS_MAX_CONNECTIONS
)
redis_client = redis.Redis(connection_pool=redis_pool)

# Constants
CLIP_EMBEDDING_DIM = config.CLIP_EMBEDDING_DIM
TEXT_EMBEDDING_DIM = config.TEXT_EMBEDDING_DIM
CACHE_TTL = config.CACHE_TTL  # 24 hours
BATCH_SIZE = config.BATCH_SIZE  # Increased batch size for better GPU utilization

def get_base_url(url: str) -> str:
    """Extract base URL for resolving relative paths"""
    from urllib.parse import urlparse, urljoin
    parsed = urlparse(url)
    # Keep the full domain and path up to the last segment
    path_parts = parsed.path.rsplit('/', 1)[0] if '/' in parsed.path else ''
    return f"{parsed.scheme}://{parsed.netloc}{path_parts}/"

def extract_video_frames(video_url: str, num_frames: int = 5) -> List[Image.Image]:
    """Extract key frames from a video URL supporting multiple formats (HLS, MP4, WebM)"""
    try:
        # For both HLS streams and direct video URLs, try to use OpenCV directly
        logging.info(f"Processing video: {video_url} using OpenCV")
        
        # First check if OpenCV is built with ffmpeg support
        build_info = cv2.getBuildInformation()
        if "FFMPEG" in build_info and "YES" in build_info.split("FFMPEG")[1].split("\n")[0]:
            logging.info("OpenCV is built with FFMPEG support")
        else:
            logging.warning("OpenCV may not have FFMPEG support, HLS streams might not work")
            
        # Create a VideoCapture object
        cap = cv2.VideoCapture(video_url)
        
        if not cap.isOpened():
            logging.error(f"Failed to open video URL: {video_url}")
            
            # Try fallback methods if it's an HLS stream
            if video_url.endswith('.m3u8'):
                return _extract_hls_frames_fallback(video_url, num_frames)
            else:
                return []
        
        # Get video properties
        fps = cap.get(cv2.CAP_PROP_FPS)
        frame_count = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
        
        logging.info(f"Video properties: FPS={fps}, Total frames={frame_count}")
        
        # For HLS streams, frame_count might be 0 since it's a live stream
        if frame_count == 0:
            # Just extract the first few frames
            frames = []
            for _ in range(num_frames * 2):  # Try to get more frames than needed in case some fail
                ret, frame = cap.read()
                if not ret:
                    break
                if len(frames) < num_frames:  # Only keep up to num_frames
                    # Convert BGR to RGB
                    frame_rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
                    frames.append(Image.fromarray(frame_rgb))
        else:
            # Calculate frame indices to extract
            if frame_count <= num_frames:
                # If we have fewer frames than requested, use all of them
                indices = list(range(frame_count))
            else:
                # Otherwise, select evenly spaced frames
                indices = [int(i * (frame_count / num_frames)) for i in range(num_frames)]
            
            frames = []
            for idx in indices:
                cap.set(cv2.CAP_PROP_POS_FRAMES, idx)
                ret, frame = cap.read()
                if ret:
                    # Convert BGR to RGB
                    frame_rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
                    frames.append(Image.fromarray(frame_rgb))
        
        cap.release()
        
        if not frames:
            logging.warning(f"No frames extracted from {video_url} using OpenCV")
            
            # Try fallback methods if it's an HLS stream
            if video_url.endswith('.m3u8'):
                return _extract_hls_frames_fallback(video_url, num_frames)
            else:
                return []
        
        return frames
    
    except Exception as e:
        logging.error(f"Error extracting video frames: {str(e)}")
        
        # Try fallback methods if it's an HLS stream
        if video_url.endswith('.m3u8'):
            return _extract_hls_frames_fallback(video_url, num_frames)
        
        return []

def _extract_hls_frames_fallback(video_url: str, num_frames: int = 5) -> List[Image.Image]:
    """Fallback method for extracting frames from HLS streams when OpenCV fails"""
    logging.info(f"Using fallback method for HLS: {video_url}")
    
    try:
        with tempfile.TemporaryDirectory() as temp_dir:
            frames_dir = os.path.join(temp_dir, "frames")
            os.makedirs(frames_dir, exist_ok=True)
            
            # Try to get thumbnail URL first as it's faster
            thumbnail_url = None
            base_name = video_url.rsplit('/', 1)[1].split('.')[0] if '/' in video_url and '.' in video_url.rsplit('/', 1)[1] else ""
            base_dir = video_url.rsplit('/', 1)[0] if '/' in video_url else ""
            
            if base_name and base_dir:
                thumbnail_candidates = [
                    f"{base_dir}/{base_name}.jpg",
                    f"{base_dir}/{base_name}_preview.jpg",
                    f"{base_dir}/{base_name}_thumb.jpg",
                    f"{base_dir}/thumbs/{base_name}.jpg",
                ]
                
                for thumb_url in thumbnail_candidates:
                    try:
                        logging.info(f"Trying thumbnail URL: {thumb_url}")
                        response = requests.head(thumb_url, timeout=5)
                        if response.status_code == 200:
                            thumbnail_url = thumb_url
                            break
                    except Exception:
                        pass
            
            # If we found a thumbnail URL, download and return it
            if thumbnail_url:
                try:
                    response = requests.get(thumbnail_url, timeout=10)
                    response.raise_for_status()
                    img = Image.open(BytesIO(response.content))
                    frames = [img]
                    logging.info(f"Successfully loaded thumbnail: {thumbnail_url}")
                except Exception as thumb_err:
                    logging.debug(f"Failed to load thumbnail {thumb_url}: {str(thumb_err)}")
            
            # If no thumbnail, try direct ffmpeg extraction with specific HLS options
            try:
                frames_path = os.path.join(frames_dir, "frame_%04d.jpg")
                
                # Use ffmpeg with HLS specific options
                cmd = [
                    'ffmpeg',
                    '-protocol_whitelist', 'file,http,https,tcp,tls',  # Allow various protocols
                    '-i', video_url,                                   # Input HLS URL
                    '-vf', 'fps=1',                                    # Extract 1 frame per second
                    '-frames:v', str(num_frames),                      # Limit frames
                    '-vsync', '0',                                     # Don't try to sync video
                    '-y', frames_path                                  # Output path
                ]
                
                logging.info(f"Running ffmpeg command: {' '.join(cmd)}")
                result = subprocess.run(cmd, capture_output=True, text=True)
                
                if result.returncode == 0:
                    # Load extracted frames
                    frame_files = sorted(glob.glob(os.path.join(frames_dir, "*.jpg")))
                    frames = []
                    for frame_file in frame_files:
                        try:
                            if os.path.getsize(frame_file) > 0:
                                frames.append(Image.open(frame_file))
                        except Exception as img_err:
                            logging.error(f"Failed to open frame {frame_file}: {str(img_err)}")
                    
                    if frames:
                        return frames
            except Exception as ffmpeg_err:
                logging.error(f"Direct ffmpeg extraction failed: {str(ffmpeg_err)}")
            
            # Final fallback: try to download and process the first segment directly
            try:
                base_url = get_base_url(video_url)
                playlist = m3u8.load(video_url)
                
                # If this is a master playlist, get the highest quality variant
                if not playlist.segments and playlist.playlists:
                    playlists = sorted(playlist.playlists,
                                    key=lambda p: p.stream_info.resolution[0] if p.stream_info and p.stream_info.resolution else (0, 0),
                                    reverse=True)
                    if playlists:
                        variant_url = urljoin(base_url, playlists[0].uri)
                        logging.info(f"Using variant playlist: {variant_url}")
                        playlist = m3u8.load(variant_url)
                        base_url = get_base_url(variant_url)
                
                # Extract thumbnail image from the segments
                if playlist.segments:
                    segment = playlist.segments[0]
                    segment_url = urljoin(base_url, segment.uri)
                    
                    # Just return the zero vector to allow the service to continue
                    logging.warning(f"All HLS extraction methods failed for {video_url}")
                    return []
            except Exception as e:
                logging.error(f"Failed to process HLS playlist: {str(e)}")
            
            return []
    except Exception as e:
        logging.error(f"Fallback HLS extraction failed: {str(e)}")
        return []

def get_video_embedding(video_url: str) -> np.ndarray:
    """Generate CLIP embedding for a video by combining key frame embeddings"""
    cache_key = f"clip_embedding:{video_url}"
    cached = redis_client.get(cache_key)
    
    if cached:
        return np.frombuffer(cached, dtype=np.float32)
    
    try:
        # Try to extract frames
        frames = extract_video_frames(video_url)
        
        # If frame extraction fails, try to get thumbnail or use HTTP request
        if not frames:
            logging.warning(f"Frame extraction failed for {video_url}, falling back to thumbnail")
            try:
                # For HLS streams, try to get the preview image if available
                if video_url.endswith('.m3u8'):
                    # Extract base name without extension
                    base_url = video_url.rsplit('/', 1)[0] if '/' in video_url else ''
                    file_name = video_url.rsplit('/', 1)[1] if '/' in video_url else video_url
                    base_name = file_name.rsplit('.', 1)[0] if '.' in file_name else file_name
                    
                    # Try common thumbnail patterns
                    thumb_candidates = [
                        f"{base_url}/{base_name}.jpg",
                        f"{base_url}/{base_name}_preview.jpg",
                        f"{base_url}/{base_name}_thumb.jpg"
                    ]
                    
                    for thumb_url in thumb_candidates:
                        try:
                            logging.info(f"Trying thumbnail URL: {thumb_url}")
                            response = requests.get(thumb_url, timeout=5)
                            if response.status_code == 200:
                                thumbnail_url = thumb_url
                                break
                        except Exception:
                            pass
                    
                    # If we found a thumbnail URL, download and return it
                    if thumbnail_url:
                        try:
                            response = requests.get(thumbnail_url, timeout=10)
                            response.raise_for_status()
                            img = Image.open(BytesIO(response.content))
                            frames = [img]
                            logging.info(f"Successfully loaded thumbnail: {thumbnail_url}")
                        except Exception as thumb_err:
                            logging.debug(f"Failed to load thumbnail {thumb_url}: {str(thumb_err)}")
                
                # If still no frames, try direct ffmpeg thumbnail extraction
                if not frames:
                    with tempfile.TemporaryDirectory() as temp_dir:
                        thumb_path = os.path.join(temp_dir, "thumb.jpg")
                        # For HLS, try downloading video first
                        if video_url.endswith('.m3u8'):
                            try:
                                # Just use the first segment directly for thumbnail
                                base_url = get_base_url(video_url)
                                playlist = m3u8.load(video_url)
                                if playlist.segments and len(playlist.segments) > 0:
                                    segment_url = urljoin(base_url, playlist.segments[0].uri)
                                    logging.info(f"Using first segment for thumbnail: {segment_url}")
                                    video_url = segment_url
                            except Exception as e:
                                logging.error(f"Error processing HLS for thumbnail: {str(e)}")
                                
                        cmd = [
                            'ffmpeg', '-i', video_url,
                            '-vframes', '1',
                            '-y', thumb_path
                        ]
                        result = subprocess.run(cmd, capture_output=True, text=True)
                        if result.returncode == 0 and os.path.exists(thumb_path):
                            frames = [Image.open(thumb_path)]
                        else:
                            raise ValueError("Thumbnail extraction failed")
            except Exception as thumb_err:
                logging.error(f"All thumbnail extraction methods failed for {video_url}: {str(thumb_err)}")
                # As a last resort, return a zero vector
                logging.warning(f"Using zero vector for {video_url}")
                zero_embedding = np.zeros(CLIP_EMBEDDING_DIM, dtype=np.float32)
                # Still cache the zero vector to avoid repeated failures
                redis_client.set(cache_key, zero_embedding.tobytes(), ex=CACHE_TTL)
                return zero_embedding
        
        # Get embeddings for each frame
        frame_embeddings = []
        with torch.cuda.amp.autocast() if device == 'cuda' else nullcontext():
            for frame in frames:
                # Preprocess frame
                frame_input = encoder.image_processor(images=frame, return_tensors="pt").to(device)
                
                # Get embedding
                with torch.no_grad():
                    frame_features = encoder.image_model.get_image_features(**frame_input)
                    frame_features /= frame_features.norm(dim=-1, keepdim=True)
                    frame_embeddings.append(frame_features.cpu().numpy())
        
        # Combine frame embeddings (average)
        embedding_np = np.mean(frame_embeddings, axis=0).astype(np.float32)
        
        # Cache the embedding
        redis_client.set(cache_key, embedding_np.tobytes(), ex=CACHE_TTL)
        
        return embedding_np
    
    except Exception as e:
        logging.error(f"Error getting video embedding for {video_url}: {str(e)}")
        # Return zero vector as last resort
        zero_embedding = np.zeros(CLIP_EMBEDDING_DIM, dtype=np.float32)
        redis_client.set(cache_key, zero_embedding.tobytes(), ex=CACHE_TTL)
        return zero_embedding

def get_image_embedding(image_url: str) -> np.ndarray:
    """Generate CLIP embedding for an image with caching"""
    cache_key = f"clip_embedding:{image_url}"
    cached = redis_client.get(cache_key)
    
    if cached:
        return np.frombuffer(cached, dtype=np.float32)
    
    try:
        # Download and process image
        response = requests.get(image_url, timeout=10)
        response.raise_for_status()  # Raise error for bad status codes
        
        # Check content type
        content_type = response.headers.get('content-type', '').lower()
        if not any(t in content_type for t in ['image/', 'video/']):
            raise ValueError(f"Invalid content type: {content_type}")
            
        try:
            image = Image.open(BytesIO(response.content))
        except Exception as e:
            logging.error(f"Failed to open image from {image_url}: {str(e)}")
            logging.error(f"Content type: {content_type}")
            logging.error(f"Content length: {len(response.content)} bytes")
            raise ValueError(f"Cannot open image: {str(e)}")
            
        # Convert to RGB if necessary
        if image.mode != 'RGB':
            image = image.convert('RGB')
            
        image_input = encoder.image_processor(images=image, return_tensors="pt").to(device)
        
        # Ensure input is float32
        image_input = image_input.float()
        
        with torch.no_grad():
            image_features = encoder.image_model.get_image_features(**image_input)
            # Ensure output is float32
            image_features = image_features.float()
            image_features /= image_features.norm(dim=-1, keepdim=True)
            embedding_np = image_features.cpu().numpy().astype(np.float32)
            
        # Cache the embedding
        redis_client.set(cache_key, embedding_np.tobytes(), ex=CACHE_TTL)
        
        return embedding_np
        
    except requests.RequestException as e:
        logging.error(f"Error downloading image from {image_url}: {str(e)}")
        raise ValueError(f"Failed to download image: {str(e)}")
    except Exception as e:
        logging.error(f"Error generating image embedding for {image_url}: {str(e)}")
        raise

def get_text_embedding(text: str) -> np.ndarray:
    """Generate text embedding with caching"""
    cache_key = f"text_embedding:{text}"
    cached = redis_client.get(cache_key)
    
    if cached:
        return np.frombuffer(cached, dtype=np.float32)
    
    try:
        with torch.no_grad():
            # Ensure input text tensor is float32
            embedding = encoder.text_encoder.encode(
                text,
                convert_to_tensor=True,
                normalize_embeddings=True,
                show_progress_bar=False
            )
            # Convert to float32 before any operations
            embedding = embedding.float()
            embedding_np = embedding.cpu().numpy().astype(np.float32)
            
        # Cache the embedding
        redis_client.set(cache_key, embedding_np.tobytes(), ex=CACHE_TTL)
        
        return embedding_np
    except Exception as e:
        logging.error(f"Error generating text embedding: {str(e)}")
        raise

def cosine_similarity(a: np.ndarray, b: np.ndarray) -> float:
    """Calculate cosine similarity between two vectors"""
    norm_a = np.linalg.norm(a)
    norm_b = np.linalg.norm(b)
    
    if norm_a == 0 or norm_b == 0:
        return 0
        
    return np.dot(a, b) / (norm_a * norm_b)

def batch_index_tags(meme_tags: List[Dict[str, Union[int, str, List[str]]]]):
    """Batch index tags for multiple memes in the stemmer service"""
    try:
        # Group all tags by meme_id
        response = requests.post(
            'http://host.docker.internal:5050/batch-index-tags',
            json={'meme_tags': meme_tags}
        )
        if response.status_code != 200:
            logging.warning(f"Failed to batch index tags: {response.text}")
    except Exception as e:
        logging.error(f"Error batch indexing tags: {str(e)}")

def normalize_arabic(text: str) -> str:
    """Normalize Arabic text by removing diacritics and normalizing characters"""
    # Common Arabic character normalizations
    text = text.replace('أ', 'ا')
    text = text.replace('إ', 'ا')
    text = text.replace('آ', 'ا')
    text = text.replace('ة', 'ه')
    text = text.replace('ي', 'ى')
    text = text.replace('ئ', 'ى')
    text = text.replace('ؤ', 'و')
    return text

def text_similarity(query: str, text: str) -> float:
    """Calculate text similarity score considering Arabic text variations"""
    # Normalize both texts
    norm_query = normalize_arabic(query.lower())
    norm_text = normalize_arabic(text.lower())
    
    # Check for exact matches first
    if norm_query in norm_text or norm_text in norm_query:
        return 1.0
        
    # Check for word-level matches
    query_words = set(norm_query.split())
    text_words = set(norm_text.split())
    
    # Calculate word overlap
    common_words = query_words & text_words
    if common_words:
        # Higher weight for more word matches
        word_match_ratio = len(common_words) / len(query_words)  # Focus on query words matched
        return min(0.7 + (word_match_ratio * 0.3), 1.0)  # Base score + word match bonus
        
    return 0.0

def index_meme(meme_id: str, content_url: str, meme_type: str, text_content: str = "") -> bool:
    """Index a meme in Redis with vector search"""
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
        
        redis_client.hset(f"meme:{meme_id}", mapping=meme_data)
        return True
        
    except Exception as e:
        logging.error(f"Error indexing meme {meme_id}: {str(e)}")
        return False

def search_memes(query: str, limit: int = 20) -> List[Dict]:
    """Search for memes using vector similarity and query expansion"""
    try:
        # Generate query embedding
        query_embedding = encoder.encode_text(query)
        
        # Expand query
        expanded_queries = encoder.expand_query(query)
        
        # Search parameters
        search_params = {
            "DIALECT": 3,  # Use dialect 3 for vector similarity
            "RETURN": ["type", "text_content", "emotions"],  # Fields to return
            "SORTBY": "__vector_score",  # Sort by vector similarity
            "LIMIT": [0, limit]  # Pagination
        }
        
        # Combine vector search with text search
        base_query = f"(@type:(image|video))"
        text_conditions = []
        
        # Add text search conditions
        for expanded_query in expanded_queries:
            text_conditions.append(f"(@text_content:{expanded_query})")
        
        if text_conditions:
            text_query = " | ".join(text_conditions)  # OR condition
            base_query = f"({base_query} | {text_query})"
        
        # Create hybrid query with vector similarity
        vector_query = redis_client.ft("meme_idx").create_query(
            base_query,
            query_params={
                "vec": query_embedding.astype(np.float32).tobytes()
            },
            **search_params
        )
        
        # Execute search
        results = redis_client.ft("meme_idx").search(vector_query)
        
        # Process results
        memes = []
        for doc in results.docs:
            meme_id = doc.id.split(":")[-1]
            emotions = json.loads(doc.emotions)
            
            # Calculate emotion-adjusted score
            base_score = 1 - float(doc.vector_score)  # Convert distance to similarity
            emotion_score = sum(emotions.values()) / len(emotions) if emotions else 0
            final_score = 0.8 * base_score + 0.2 * emotion_score  # Weight vector similarity more
            
            memes.append({
                "id": meme_id,
                "type": doc.type,
                "text_content": doc.text_content,
                "emotions": emotions,
                "score": final_score
            })
        
        # Sort by final score
        memes.sort(key=lambda x: x["score"], reverse=True)
        return memes
        
    except Exception as e:
        logging.error(f"Error searching memes: {str(e)}")
        return []

@app.route("/index", methods=["POST"])
def index():
    """Index a new meme"""
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
    """Search for memes"""
    try:
        query = request.args.get("q")
        limit = int(request.args.get("limit", 20))
        
        if not query:
            return jsonify({"error": "Missing query parameter"}), 400
        
        results = search_memes(query, limit)
        return jsonify({"results": results}), 200
        
    except Exception as e:
        return jsonify({"error": str(e)}), 500

@app.route('/index_meme', methods=['POST'])
def index_meme():
    """Index a meme with both image and text embeddings"""
    try:
        data = request.get_json()
        if not data:
            return jsonify({'error': 'No data provided'}), 400

        meme_id = data.get('id')
        media_url = data.get('media_url')
        tags = data.get('tags', [])
        caption = data.get('caption', '')
        meme_type = data.get('meme_type', 'image')

        if not meme_id or not media_url:
            return jsonify({'error': 'Missing required fields'}), 400

        logging.info(f"Indexing meme {meme_id} with {len(tags)} tags")

        # Generate text embedding from tags and caption
        text_content = ' '.join(tags + [caption] if caption else tags)
        text_embedding = get_text_embedding(text_content)

        # Generate media embedding based on type
        try:
            if meme_type == 'video':
                img_embedding = get_video_embedding(media_url)
            else:
                img_embedding = get_image_embedding(media_url)
        except Exception as e:
            logging.error(f"Error generating media embedding: {str(e)}")
            return jsonify({
                'status': 'error',
                'message': f"Error generating media embedding: {str(e)}"
            }), 500

        # Store embeddings in Redis
        meme_key = f"meme:{meme_id}"
        redis_client.hset(
            meme_key,
            mapping={
                'text_vector': text_embedding.tobytes(),
                'img_vector': img_embedding.tobytes(),
                'tags': json.dumps(tags),
                'caption': caption,
                'meme_type': meme_type
            }
        )

        # Queue tags for batch indexing
        batch_index_tags([{
            'meme_id': meme_id,
            'tags': tags
        }])

        return jsonify({'status': 'success'}), 200

    except Exception as e:
        logging.error(f"Error indexing meme: {str(e)}")
        return jsonify({'status': 'error', 'message': str(e)}), 500

@app.route('/batch_index_memes', methods=['POST'])
def batch_index_memes():
    """Batch index multiple memes with embeddings"""
    try:
        data = request.get_json()
        if not data or 'memes' not in data:
            return jsonify({'error': 'No memes provided'}), 400

        memes = data['memes']
        if not isinstance(memes, list):
            return jsonify({'error': 'Memes must be a list'}), 400

        logging.info(f"Batch indexing {len(memes)} memes")
        
        # Process in larger batches for better GPU utilization
        batch_size = BATCH_SIZE
        for i in range(0, len(memes), batch_size):
            batch = memes[i:i+batch_size]
            
            # Collect all text content and media URLs for batch processing
            text_contents = []
            media_urls = []
            meme_ids = []
            meme_types = []
            
            for meme in batch:
                meme_id = meme.get('id')
                media_url = meme.get('media_url')
                tags = meme.get('tags', [])
                caption = meme.get('caption', '')
                meme_type = meme.get('meme_type', 'image')
                
                if not meme_id or not media_url:
                    logging.warning(f"Skipping meme with missing id or media_url: {meme}")
                    continue
                    
                text_content = ' '.join(tags + [caption] if caption else tags)
                text_contents.append(text_content)
                media_urls.append(media_url)
                meme_ids.append(meme_id)
                meme_types.append(meme_type)

            if not meme_ids:
                continue

            # Generate text embeddings in larger batches
            try:
                with torch.cuda.amp.autocast() if device == 'cuda' else nullcontext():
                    text_embeddings = []
                    for j in range(0, len(text_contents), BATCH_SIZE):
                        sub_batch = text_contents[j:j+BATCH_SIZE]
                        batch_embeddings = encoder.text_encoder.encode(
                            sub_batch,
                            convert_to_tensor=True,
                            normalize_embeddings=True,
                            batch_size=BATCH_SIZE
                        )
                        text_embeddings.extend(batch_embeddings.cpu().numpy())
            except Exception as e:
                logging.error(f"Error generating text embeddings: {str(e)}")
                continue

            # Generate media embeddings in batches
            try:
                with torch.cuda.amp.autocast() if device == 'cuda' else nullcontext():
                    img_embeddings = []
                    for j in range(0, len(media_urls), BATCH_SIZE):
                        sub_batch_urls = media_urls[j:j+BATCH_SIZE]
                        sub_batch_types = meme_types[j:j+BATCH_SIZE]
                        
                        # Process images and videos separately
                        batch_embeddings = []
                        for url, mtype in zip(sub_batch_urls, sub_batch_types):
                            if mtype == 'video':
                                emb = get_video_embedding(url)
                            else:
                                emb = get_image_embedding(url)
                            batch_embeddings.append(emb)
                        
                        img_embeddings.extend(batch_embeddings)
                        
                        # Clear CUDA cache periodically
                        if device == 'cuda':
                            torch.cuda.empty_cache()
            except Exception as e:
                logging.error(f"Error generating media embeddings: {str(e)}")
                continue

            # Store embeddings in Redis using pipeline for better performance
            with redis_client.pipeline() as pipe:
                for j, (meme_id, text_emb, img_emb) in enumerate(zip(meme_ids, text_embeddings, img_embeddings)):
                    meme_key = f"meme:{meme_id}"
                    pipe.hset(
                        meme_key,
                        mapping={
                            'text_vector': text_emb.tobytes(),
                            'img_vector': img_emb.tobytes(),
                            'tags': json.dumps(batch[j].get('tags', [])),
                            'caption': batch[j].get('caption', ''),
                            'meme_type': meme_types[j]
                        }
                    )
                pipe.execute()

            # Clear memory
            del text_embeddings
            del img_embeddings
            if device == 'cuda':
                torch.cuda.empty_cache()
            gc.collect()

        return jsonify({'status': 'success', 'indexed': len(memes)}), 200

    except Exception as e:
        logging.error(f"Error in batch indexing: {str(e)}")
        return jsonify({'status': 'error', 'message': str(e)}), 500

@app.route('/search_by_text', methods=['POST'])
def search_by_text():
    """Search for memes using text query"""
    try:
        data = request.get_json()
        if not data or 'query' not in data:
            return jsonify({'error': 'Missing query'}), 400

        query = data['query']
        limit = int(data.get('limit', 10))
        
        logging.info(f"Searching for query: {query}")

        # Generate query embedding
        try:
            query_embedding = get_text_embedding(query)
            logging.info(f"Generated query embedding with shape: {query_embedding.shape}")
            logging.info(f"Query embedding norm: {np.linalg.norm(query_embedding)}")
        except Exception as e:
            logging.error(f"Error generating query embedding: {str(e)}")
            return jsonify({
                'status': 'error',
                'message': f"Error generating query embedding: {str(e)}"
            }), 500

        try:
            # Execute search using ft() method
            search_index = redis_client.ft('idx:meme')
            
            # List all keys in Redis for debugging
            all_keys = redis_client.keys('meme:*')
            logging.info(f"Found {len(all_keys)} meme keys in Redis")
            
            # Build query string that matches all documents
            query_string = '*'
            
            # Execute search
            results = search_index.search(query_string)
            logging.info(f"Search returned {len(results.docs)} documents")
            
            # Process results
            meme_results = []
            for doc in results.docs:
                try:
                    # Get meme data from Redis hash
                    meme_key = f"meme:{doc.id.split(':')[1]}"
                    meme_data = redis_client.hgetall(meme_key)
                    
                    logging.info(f"Processing meme {meme_key}")
                    logging.info(f"Meme data keys: {list(meme_data.keys())}")
                    
                    if not meme_data:
                        logging.warning(f"No data found for meme {meme_key}")
                        continue
                        
                    if b'text_vector' not in meme_data:
                        logging.warning(f"No text vector found for meme {meme_key}")
                        continue
                        
                    # Calculate vector similarity score
                    doc_vector = np.frombuffer(meme_data[b'text_vector'], dtype=np.float32)
                    logging.info(f"Loaded document vector with shape: {doc_vector.shape}")
                    logging.info(f"Document vector norm: {np.linalg.norm(doc_vector)}")
                    
                    score = cosine_similarity(query_embedding, doc_vector)
                    logging.info(f"Calculated similarity score: {score}")
                    
                    if not np.isnan(score) and not np.isinf(score):
                        meme_id = doc.id.split(':')[1]  # Remove 'meme:' prefix
                        meme_results.append({
                            'id': int(meme_id),
                            'score': float(score)
                        })
                except (ValueError, IndexError, AttributeError) as e:
                    logging.warning(f"Error processing result doc {doc.id}: {str(e)}")
                    continue
            
            logging.info(f"Found {len(meme_results)} valid results")
            
            # Sort by score
            meme_results.sort(key=lambda x: x['score'], reverse=True)
            
            # Limit results
            meme_results = meme_results[:limit]
            
            logging.info(f"Returning {len(meme_results)} results")

            return jsonify({
                'status': 'success',
                'results': meme_results
            })

        except Exception as e:
            logging.error(f"Redis search error: {str(e)}")
            return jsonify({
                'status': 'error',
                'message': f"Search execution error: {str(e)}"
            }), 500

    except Exception as e:
        logging.error(f"Search error: {str(e)}")
        return jsonify({'status': 'error', 'message': str(e)}), 500

@app.route('/text_search', methods=['POST'])
def text_search():
    """Search for memes using text query and return scores"""
    try:
        data = request.get_json()
        if not data or 'query' not in data:
            return jsonify({'error': 'No query provided'}), 400

        query = data['query']
        filters = data.get('filters', {})
        limit = data.get('limit', 10)
        meme_type = filters.get('meme_type')

        # Get text embeddings for query
        query_embedding = None
        if query:
            response = requests.post(
                'http://host.docker.internal:5050/batch-embeddings',
                json={'texts': [query]}
            )
            embeddings = response.json().get('embeddings', [])
            if embeddings:
                query_embedding = np.array(embeddings[0], dtype=np.float32)

        # Search Redis for matching memes
        results = []
        if query_embedding is not None:
            # Get all meme keys
            meme_keys = redis_client.keys('meme:*')
            
            for meme_key in meme_keys:
                try:
                    meme_data = redis_client.hgetall(meme_key)
                    if not meme_data:
                        continue

                    # Check meme type if filter is applied
                    if meme_type and meme_data.get(b'meme_type', b'').decode() != meme_type:
                        continue

                    # Get text vector and calculate semantic similarity
                    text_vector = np.frombuffer(meme_data[b'text_vector'], dtype=np.float32)
                    semantic_similarity = cosine_similarity(query_embedding, text_vector)

                    # Get meme ID from key
                    meme_id = int(meme_key.decode().split(':')[1])
                    
                    # Get tags and caption
                    tags = json.loads(meme_data[b'tags'].decode())
                    caption = meme_data.get(b'caption', b'').decode()
                    
                    # Calculate text similarity scores
                    tag_scores = []
                    for tag in tags:
                        # Check both Arabic and English tags
                        tag_sim = text_similarity(query, tag)
                        # For Arabic tags, check word by word
                        if any(c.isalpha() and ord(c) > 128 for c in tag):  # Has Arabic chars
                            words = tag.split()
                            for word in words:
                                word_sim = text_similarity(query, word)
                                if word_sim > tag_sim:
                                    tag_sim = word_sim
                        tag_scores.append(tag_sim)
                    
                    caption_score = text_similarity(query, caption) if caption else 0
                    
                    # Get best tag match score
                    best_tag_score = max(tag_scores) if tag_scores else 0
                    
                    # Calculate text match score with higher weights
                    text_match_score = max(
                        caption_score,  # Full weight for caption matches
                        best_tag_score  # Full weight for tag matches
                    )
                    
                    # Boost score if we have both good semantic and text matches
                    if semantic_similarity > 0.5 and text_match_score > 0.5:
                        boost = min(semantic_similarity, text_match_score) * 0.2
                    else:
                        boost = 0
                    
                    # Final score prioritizes text matches but considers semantic similarity
                    final_score = max(
                        text_match_score * 0.8 + semantic_similarity * 0.2,  # Text-focused score
                        semantic_similarity * 0.8 + text_match_score * 0.2,  # Semantic-focused score
                    ) + boost
                    
                    # Ensure very relevant results get high scores
                    if text_match_score > 0.8 or semantic_similarity > 0.8:
                        final_score = max(final_score, 0.9)  # Minimum score for highly relevant results
                    
                    results.append({
                        'id': meme_id,
                        'score': float(final_score),
                        'semantic_similarity': float(semantic_similarity),
                        'text_match_score': float(text_match_score),
                        'caption_score': float(caption_score),
                        'best_tag_score': float(best_tag_score),
                        'boost': float(boost)
                    })
                except Exception as e:
                    logging.error(f"Error processing meme {meme_key}: {str(e)}")
                    continue

            # Sort by score
            results = sorted(results, key=lambda x: x['score'], reverse=True)
            
            # Apply limit
            results = results[:limit]

        return jsonify({'results': results}), 200

    except Exception as e:
        logging.error(f"Error in text search: {str(e)}")
        return jsonify({'error': str(e)}), 500

def setup_redis_index():
    """Create Redis search indices for hybrid search"""
    try:
        # Drop existing indices if they exist
        try:
            redis_client.ft("idx:meme").dropindex()
        except:
            pass

        # Define fields for the search index
        schema = (
            TagField("meme_type"),
            TextField("tags", weight=3.0),
            TextField("caption"),
            VectorField("img_vector",
                "FLAT", {
                    "TYPE": "FLOAT32",
                    "DIM": CLIP_EMBEDDING_DIM,
                    "DISTANCE_METRIC": "COSINE"
                }),
            VectorField("text_vector",
                "FLAT", {
                    "TYPE": "FLOAT32",
                    "DIM": TEXT_EMBEDDING_DIM,
                    "DISTANCE_METRIC": "COSINE"
                })
        )

        # Create the index
        redis_client.ft("idx:meme").create_index(
            schema,
            definition=IndexDefinition(prefix=["meme:"])
        )
        logging.info("Redis search index created successfully")
    except Exception as e:
        logging.error(f"Error setting up Redis index: {str(e)}")
        raise

if __name__ == '__main__':
    # Initialize Redis index
    setup_redis_index()
    app.run(host=config.SERVER_HOST, port=config.SEARCH_PORT)
