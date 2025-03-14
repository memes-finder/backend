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
import datetime

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
        frames = []
        extraction_successful = False
        
        # First attempt: try using OpenCV directly
        if not extraction_successful:
            try:
                logging.info(f"Processing video: {video_url} using OpenCV")
                
                # Check if OpenCV is built with ffmpeg support
                build_info = cv2.getBuildInformation()
                if "FFMPEG" in build_info and "YES" in build_info.split("FFMPEG")[1].split("\n")[0]:
                    logging.info("OpenCV is built with FFMPEG support")
                else:
                    logging.warning("OpenCV may not have FFMPEG support, HLS streams might not work")
                
                # Create a VideoCapture object
                cap = cv2.VideoCapture(video_url)
                
                if cap.isOpened():
                    # Get video properties
                    fps = cap.get(cv2.CAP_PROP_FPS)
                    frame_count = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
                    
                    logging.info(f"Video properties: FPS={fps}, Total frames={frame_count}")
                    
                    # For streams where frame_count can't be determined or is unreliable
                    frame_capture_attempts = 0
                    frames = []
                    max_attempts = max(30, num_frames * 3)  # Try more frames than needed
                    
                    # Just read frames sequentially until we have enough
                    while len(frames) < num_frames and frame_capture_attempts < max_attempts:
                        ret, frame = cap.read()
                        if not ret:
                            break
                        
                        # Only keep frames at regular intervals
                        if frame_capture_attempts % max(1, int(max_attempts / num_frames)) == 0:
                            # Convert BGR to RGB
                            frame_rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
                            frames.append(Image.fromarray(frame_rgb))
                        
                        frame_capture_attempts += 1
                    
                    cap.release()
                    
                    if frames:
                        logging.info(f"Successfully extracted {len(frames)} frames using OpenCV")
                        extraction_successful = True
                    else:
                        logging.warning(f"No frames extracted from {video_url} using OpenCV sequential reading")
                else:
                    logging.error(f"Failed to open video URL with OpenCV: {video_url}")
            except Exception as e:
                logging.error(f"OpenCV extraction error: {str(e)}")
        
        # Second attempt: Use ffmpeg directly for HLS
        if not extraction_successful and video_url.endswith('.m3u8'):
            try:
                logging.info(f"Attempting direct ffmpeg extraction for: {video_url}")
                
                with tempfile.TemporaryDirectory() as temp_dir:
                    frames_dir = os.path.join(temp_dir, "frames")
                    os.makedirs(frames_dir, exist_ok=True)
                    frames_path = os.path.join(frames_dir, "frame_%04d.jpg")
                    
                    # Try with very relaxed settings to handle corrupted streams
                    cmd = [
                        'ffmpeg',
                        '-protocol_whitelist', 'file,http,https,tcp,tls',
                        '-i', video_url,
                        '-vf', 'fps=1',
                        '-frames:v', str(num_frames),
                        '-vsync', '0',  # Don't sync video - good for corrupted files
                        '-err_detect', 'ignore_err',  # Ignore decoding errors
                        '-fflags', '+genpts+igndts+discardcorrupt',  # Handle corrupt data
                        '-y', frames_path
                    ]
                    
                    logging.info(f"Running ffmpeg command: {' '.join(cmd)}")
                    result = subprocess.run(cmd, capture_output=True, text=True)
                    
                    # Check if frames were generated
                    frame_files = sorted(glob.glob(os.path.join(frames_dir, "*.jpg")))
                    if frame_files:
                        frames = []
                        for frame_file in frame_files:
                            try:
                                if os.path.exists(frame_file) and os.path.getsize(frame_file) > 0:
                                    frames.append(Image.open(frame_file))
                            except Exception as img_err:
                                logging.error(f"Failed to open frame file: {str(img_err)}")
                        
                        if frames:
                            logging.info(f"Successfully extracted {len(frames)} frames using direct ffmpeg")
                            extraction_successful = True
                        else:
                            logging.warning("No valid frames found using direct ffmpeg extraction")
                    else:
                        logging.warning("No frame files generated by ffmpeg")
            except Exception as e:
                logging.error(f"Direct ffmpeg extraction error: {str(e)}")
        
        # Third attempt: Try to get thumbnails
        if not extraction_successful and video_url.endswith('.m3u8'):
            try:
                logging.info(f"Attempting to find thumbnails for: {video_url}")
                
                # Try to infer thumbnail URL
                thumbnail_url = None
                base_name = video_url.rsplit('/', 1)[1].split('.')[0] if '/' in video_url and '.' in video_url.rsplit('/', 1)[1] else ""
                base_dir = video_url.rsplit('/', 1)[0] if '/' in video_url else ""
                
                if base_name and base_dir:
                    # Create variations of possible thumbnail paths
                    thumbnail_candidates = [
                        f"{base_dir}/{base_name}.jpg",
                        f"{base_dir}/{base_name}_preview.jpg",
                        f"{base_dir}/{base_name}_thumb.jpg"
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
                
                # If we found a thumbnail URL, download and use it
                if thumbnail_url:
                    try:
                        response = requests.get(thumbnail_url, timeout=10)
                        response.raise_for_status()
                        img = Image.open(BytesIO(response.content))
                        frames = [img]
                        logging.info(f"Successfully loaded thumbnail: {thumbnail_url}")
                        extraction_successful = True
                    except Exception as thumb_err:
                        logging.error(f"Failed to download thumbnail: {str(thumb_err)}")
            except Exception as e:
                logging.error(f"Thumbnail extraction error: {str(e)}")
        
        # Final attempt: Try to use the HLS segments directly
        if not extraction_successful and video_url.endswith('.m3u8'):
            try:
                logging.info(f"Attempting to extract from HLS segments for: {video_url}")
                
                with tempfile.TemporaryDirectory() as temp_dir:
                    # Download and process segments
                    base_url = get_base_url(video_url)
                    playlist = m3u8.load(video_url)
                    
                    # Handle master playlists
                    if not playlist.segments and playlist.playlists:
                        playlists = sorted(playlist.playlists,
                                       key=lambda p: p.stream_info.resolution[0] if p.stream_info and p.stream_info.resolution else (0, 0),
                                       reverse=True)
                        if playlists:
                            variant_url = urljoin(base_url, playlists[0].uri)
                            logging.info(f"Using variant playlist: {variant_url}")
                            playlist = m3u8.load(variant_url)
                            base_url = get_base_url(variant_url)
                    
                    # Process segments
                    if playlist.segments:
                        logging.info(f"Found {len(playlist.segments)} segments in playlist")
                        
                        for i, segment in enumerate(playlist.segments[:2]):  # Just try first two segments
                            segment_url = urljoin(base_url, segment.uri)
                            segment_path = os.path.join(temp_dir, f"segment_{i}.ts")
                            
                            try:
                                # Download segment
                                logging.info(f"Downloading segment {i}: {segment_url}")
                                response = requests.get(segment_url, timeout=10)
                                response.raise_for_status()
                                
                                with open(segment_path, 'wb') as f:
                                    f.write(response.content)
                                
                                if os.path.getsize(segment_path) > 0:
                                    # Extract frame from segment
                                    frames_dir = os.path.join(temp_dir, f"segment_{i}_frames")
                                    os.makedirs(frames_dir, exist_ok=True)
                                    frame_path = os.path.join(frames_dir, "frame.jpg")
                                    
                                    cmd = [
                                        'ffmpeg',
                                        '-f', 'mpegts',        # Force MPEG-TS format
                                        '-i', segment_path,    # Input segment file
                                        '-vframes', '1',       # Just get one frame
                                        '-an',                 # No audio
                                        '-y', frame_path
                                    ]
                                    
                                    logging.info(f"Extracting frame from segment: {' '.join(cmd)}")
                                    result = subprocess.run(cmd, capture_output=True, text=True)
                                    
                                    # Check if frame was generated
                                    if os.path.exists(frame_path) and os.path.getsize(frame_path) > 0:
                                        try:
                                            frame = Image.open(frame_path)
                                            frames.append(frame)
                                            logging.info(f"Successfully extracted frame from segment {i}")
                                            if len(frames) >= num_frames:
                                                break
                                        except Exception as img_err:
                                            logging.error(f"Failed to open frame from segment {i}: {str(img_err)}")
                            except Exception as segment_err:
                                logging.error(f"Failed to process segment {i}: {str(segment_err)}")
                        
                        if frames:
                            logging.info(f"Successfully extracted {len(frames)} frames from HLS segments")
                            extraction_successful = True
            except Exception as e:
                logging.error(f"HLS segment extraction error: {str(e)}")
        
        # If we have frames, return them
        if frames:
            return frames[:num_frames]  # Only return up to num_frames
        
        # If all methods failed, log the error and return empty list
        logging.error(f"All frame extraction methods failed for: {video_url}")
        return []
        
    except Exception as e:
        logging.error(f"Video frame extraction failed: {str(e)}")
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
    """Get CLIP image embedding for an image URL"""
    try:
        # Check if we have a cached embedding
        cache_key = f"cache:image_embedding:{hashlib.md5(image_url.encode()).hexdigest()}"
        cached = redis_client.get(cache_key)
        if cached:
            # Parse cached embedding
            try:
                embedding = np.frombuffer(cached, dtype=np.float32)
                if embedding.shape[0] == config.CLIP_EMBEDDING_DIM:
                    logging.info(f"Using cached image embedding for {image_url}")
                    return embedding
                else:
                    logging.warning(f"Cached image embedding has wrong dimensions: {embedding.shape[0]}, regenerating")
            except Exception as cache_error:
                logging.error(f"Error parsing cached image embedding: {str(cache_error)}")
                # Continue to regenerate the embedding
        
        # Download image
        logging.info(f"Downloading image from {image_url}")
        local_path = cached_download(image_url)
        
        # Open image
        if not local_path or not os.path.exists(local_path):
            logging.error(f"Failed to download image from {image_url}")
            return None
            
        try:
            img = Image.open(local_path).convert('RGB')
        except Exception as e:
            logging.error(f"Failed to open image {local_path}: {str(e)}")
            return None
        
        # Generate embedding
        with torch.no_grad():
            try:
                # Preprocess image
                image_input = encoder.clip_processor(images=img, return_tensors="pt")["pixel_values"].to(encoder.device)
                
                # Generate embedding
                embedding = encoder.clip_model.get_image_features(image_input)
                
                # Convert to numpy array and ensure it's 1D
                embedding = embedding.cpu().numpy()
                original_shape = embedding.shape
                embedding = embedding.flatten()
                
                logging.info(f"Generated embedding with shape {original_shape}, flattened to {embedding.shape}")
                
                # Ensure embedding has the correct dimensions
                if embedding.shape[0] != config.CLIP_EMBEDDING_DIM:
                    logging.warning(f"Image embedding dimension mismatch: got {embedding.shape[0]}, expected {config.CLIP_EMBEDDING_DIM}")
                    
                    # Resize embedding to match expected dimensions
                    if embedding.shape[0] < config.CLIP_EMBEDDING_DIM:
                        # Pad with zeros if smaller (ensure padding is 1D)
                        padding_size = config.CLIP_EMBEDDING_DIM - embedding.shape[0]
                        padding = np.zeros(padding_size, dtype=np.float32)
                        logging.info(f"Adding padding with shape {padding.shape} to embedding with shape {embedding.shape}")
                        embedding = np.concatenate([embedding, padding])
                    else:
                        # Truncate if larger
                        embedding = embedding[:config.CLIP_EMBEDDING_DIM]
                
                # Normalize embedding
                norm = np.linalg.norm(embedding)
                if norm > 0:
                    embedding = embedding / norm
                
                # Cache embedding
                redis_client.set(cache_key, embedding.tobytes())
                
                return embedding
            except Exception as e:
                logging.error(f"Failed to generate image embedding: {str(e)}")
                return None
    except Exception as e:
        logging.error(f"Failed to generate image embedding for {image_url}: {str(e)}")
        return None

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
        # Use config/env vars for stemmer service
        server_host = os.getenv('SERVER_HOST', config.SERVER_HOST)
        stemmer_port = os.getenv('STEMMER_PORT', config.STEMMER_PORT)
        stemmer_url = f'http://{server_host}:{stemmer_port}/batch-index-tags'
        
        logging.info(f"Connecting to stemmer for batch indexing at {stemmer_url}")
        
        # Group all tags by meme_id
        response = requests.post(
            stemmer_url,
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

def text_similarity(text1, text2):
    """Calculate text similarity between two strings"""
    if not text1 or not text2:
        return 0.0
    
    # Normalize texts
    text1 = text1.lower().strip()
    text2 = text2.lower().strip()
    
    # Check for exact matches first (higher weight for Arabic)
    if text1 == text2:
        return 1.0
    
    # Arabic-specific handling - check if either text contains Arabic characters
    has_arabic_1 = any(is_arabic_char(c) for c in text1)
    has_arabic_2 = any(is_arabic_char(c) for c in text2)
    
    # If one is Arabic and the other isn't, split into words and check for translations
    if has_arabic_1 != has_arabic_2:
        words1 = text1.split()
        words2 = text2.split()
        
        # Check for any word-level exact matches
        for w1 in words1:
            for w2 in words2:
                if w1 == w2 or w1 in w2 or w2 in w1:
                    return 0.9  # Almost perfect match if we find a direct word match
        
        # If both texts have multiple words, try more partial matching
        if len(words1) > 1 and len(words2) > 1:
            word_matches = 0
            for w1 in words1:
                for w2 in words2:
                    # Check for partial word matches (min 3 chars to avoid coincidental matches)
                    if len(w1) >= 3 and len(w2) >= 3 and (w1[:3] in w2 or w2[:3] in w1):
                        word_matches += 1
                        break
            
            if word_matches > 0:
                return min(0.8, 0.5 + (word_matches / max(len(words1), len(words2)) * 0.5))
    
    # Check for direct substring matches (very important for Arabic search)
    if text1 in text2 or text2 in text1:
        return 0.95
    
    # For Arabic text, prioritize character-level matching
    if has_arabic_1 and has_arabic_2:
        # Calculate character overlap ratio
        common_chars = set(text1) & set(text2)
        total_chars = set(text1) | set(text2)
        char_similarity = len(common_chars) / max(1, len(total_chars))
        
        # Calculate word-level similarity for Arabic
        words1 = text1.split()
        words2 = text2.split()
        
        word_matches = 0
        for w1 in words1:
            for w2 in words2:
                # For Arabic, even partial matches are significant
                if len(w1) >= 2 and len(w2) >= 2:
                    if w1[:2] in w2 or w2[:2] in w1:
                        word_matches += 1
                        break
        
        word_similarity = word_matches / max(1, max(len(words1), len(words2)))
        
        # Combine with higher weight to character-level for Arabic
        return max(char_similarity * 0.4 + word_similarity * 0.6, 0.7 if text1[:2] in text2 or text2[:2] in text1 else 0)
    
    # For non-Arabic text, use standard similarity calculation
    # Calculate Jaccard similarity for word sets
    words1 = set(text1.split())
    words2 = set(text2.split())
    
    intersection = words1.intersection(words2)
    union = words1.union(words2)
    
    if not union:
        return 0.0
    
    return len(intersection) / len(union)

def is_arabic_char(char):
    """Check if a character is Arabic"""
    return '\u0600' <= char <= '\u06FF' or '\u0750' <= char <= '\u077F' or '\u08A0' <= char <= '\u08FF'

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
        vector_query = redis_client.ft(config.REDIS_INDEX_NAME).create_query(
            base_query,
            query_params={
                "vec": query_embedding.astype(np.float32).tobytes()
            },
            **search_params
        )
        
        # Execute search
        results = redis_client.ft(config.REDIS_INDEX_NAME).search(vector_query)
        
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
    """Batch index memes with both text and image embeddings"""
    try:
        data = request.get_json()
        if not data or 'memes' not in data:
            return jsonify({'error': 'No memes provided'}), 400
        
        memes = data['memes']
        if not memes:
            return jsonify({'message': 'No memes to index'}), 200
            
        results = {
            'success': [],
            'failed': [],
            'warnings': []
        }
        
        for meme_data in memes:
            try:
                meme_id = str(meme_data.get('id'))
                if not meme_id:
                    results['failed'].append({'error': 'Missing meme ID'})
                    continue
                    
                # Extract meme data
                content_url = meme_data.get('url')
                meme_type = meme_data.get('type', 'image')
                text_content = meme_data.get('text_content', '')
                caption = meme_data.get('caption', '')
                
                # Get tags
                tags = meme_data.get('tags', {})
                if isinstance(tags, list):
                    tags = {'primary': tags}  # Convert list to dict for backward compatibility
                
                # Generate text embedding
                logging.info(f"Generating text embedding for meme {meme_id}")
                text_embedding = None
                
                try:
                    if text_content or caption or tags:
                        # Combine all text for embedding
                        all_text = []
                        
                        if caption:
                            all_text.append(caption)
                            
                        if text_content:
                            all_text.append(text_content)
                            
                        # Add tags
                        for tag_list in tags.values():
                            if isinstance(tag_list, list):
                                all_text.extend(tag_list)
                        
                        combined_text = ' '.join(all_text)
                        if combined_text.strip():
                            text_embedding = get_text_embedding(combined_text)
                            
                            # Validate dimensions
                            if text_embedding is not None and text_embedding.shape[0] != config.TEXT_EMBEDDING_DIM:
                                logging.warning(f"Text embedding dimension mismatch for meme {meme_id}: got {text_embedding.shape[0]}, expected {config.TEXT_EMBEDDING_DIM}")
                                
                                # Ensure it's a 1D array
                                if len(text_embedding.shape) > 1:
                                    text_embedding = text_embedding.flatten()
                                
                                # Resize the embedding
                                if text_embedding.shape[0] < config.TEXT_EMBEDDING_DIM:
                                    # Pad with zeros if smaller (ensure padding is 1D)
                                    padding_size = config.TEXT_EMBEDDING_DIM - text_embedding.shape[0]
                                    padding = np.zeros(padding_size, dtype=np.float32)
                                    logging.info(f"Adding text padding with shape {padding.shape} to embedding with shape {text_embedding.shape}")
                                    text_embedding = np.concatenate([text_embedding, padding])
                                else:
                                    # Truncate if larger
                                    text_embedding = text_embedding[:config.TEXT_EMBEDDING_DIM]
                                
                                # Normalize after resizing
                                norm = np.linalg.norm(text_embedding)
                                if norm > 0:
                                    text_embedding = text_embedding / norm
                                    
                                logging.info(f"Resized text embedding to {config.TEXT_EMBEDDING_DIM} dimensions")
                except Exception as text_error:
                    logging.error(f"Error generating text embedding for meme {meme_id}: {str(text_error)}")
                    results['warnings'].append({
                        'id': meme_id,
                        'warning': f"Text embedding generation failed: {str(text_error)}"
                    })
                
                # Generate image embedding for images and videos
                logging.info(f"Processing image embedding for meme {meme_id}")
                image_embedding = None
                
                if content_url and meme_type in ['image', 'video', 'gif']:
                    try:
                        image_embedding = get_or_generate_image_embedding(meme_id, content_url)
                    except Exception as img_error:
                        logging.error(f"Failed to generate image embedding for meme {meme_id}: {str(img_error)}")
                        results['warnings'].append({
                            'id': meme_id,
                            'warning': f"Image embedding generation failed: {str(img_error)}"
                        })
                
                # Index the meme in Redis
                try:
                    redis_key = f"meme:{meme_id}"
                    
                    # Prepare data for Redis
                    redis_data = {
                        'meme_id': meme_id,
                        'content_url': content_url,
                        'meme_type': meme_type,
                        'tags': json.dumps(tags),
                        'caption': caption,
                        'text_content': text_content,
                        'indexed_at': datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                    }
                    
                    # Add emotions field (empty JSON object) if not present
                    if 'emotions' not in meme_data:
                        redis_data['emotions'] = '{}'
                    else:
                        redis_data['emotions'] = json.dumps(meme_data['emotions'])
                    
                    # Add text vector if available
                    if text_embedding is not None:
                        redis_data['text_vector'] = text_embedding.tobytes()
                    
                    # Add image vector if available
                    if image_embedding is not None:
                        redis_data['image_vector'] = image_embedding.tobytes()
                    
                    # Save to Redis
                    redis_client.hmset(redis_key, redis_data)
                    
                    # Add to success list
                    results['success'].append({
                        'id': meme_id,
                        'has_text_vector': text_embedding is not None,
                        'has_image_vector': image_embedding is not None
                    })
                    
                    logging.info(f"Successfully indexed meme {meme_id}")
                except Exception as redis_error:
                    logging.error(f"Failed to index meme {meme_id} in Redis: {str(redis_error)}")
                    results['failed'].append({
                        'id': meme_id,
                        'error': f"Redis indexing failed: {str(redis_error)}"
                    })
            except Exception as meme_error:
                logging.error(f"Error processing meme {meme_id if 'meme_id' in locals() else 'unknown'}: {str(meme_error)}")
                results['failed'].append({
                    'id': meme_id if 'meme_id' in locals() else 'unknown',
                    'error': f"General processing error: {str(meme_error)}"
                })
        
        return jsonify({
            'message': f"Processed {len(memes)} memes",
            'results': results
        }), 200
        
    except Exception as e:
        logging.error(f"Error in batch index memes: {str(e)}")
        return jsonify({'error': str(e)}), 500

@app.route('/text_search', methods=['POST'])
def text_search():
    """Search for memes using text query and return scores"""
    try:
        data = request.get_json()
        if not data or 'query' not in data:
            return jsonify({'error': 'No query provided'}), 400

        query = data['query'].strip()
        filters = data.get('filters', {})
        limit = data.get('limit', 10)
        meme_type = filters.get('meme_type')
        
        # Check if query has Arabic characters
        has_arabic = any(is_arabic_char(c) for c in query)
        logging.info(f"Searching for query: '{query}' (has_arabic: {has_arabic})")
        
        # Get text embeddings for query
        query_embedding = None
        if query:
            try:
                server_host = os.getenv('SERVER_HOST', config.SERVER_HOST)
                stemmer_port = os.getenv('STEMMER_PORT', config.STEMMER_PORT)
                stemmer_url = f'http://{server_host}:{stemmer_port}/batch-embeddings'
                
                logging.info(f"Connecting to stemmer at {stemmer_url}")
                response = requests.post(
                    stemmer_url,
                    json={'texts': [query]}
                )
                embeddings = response.json().get('embeddings', [])
                if embeddings:
                    query_embedding = np.array(embeddings[0], dtype=np.float32)
            except Exception as e:
                logging.error(f"Failed to get embeddings from stemmer: {str(e)}")
                # Fallback to local text encoding
                logging.info("Falling back to local text encoding")
                query_embedding = encoder.text_encoder.encode(
                    query,
                    convert_to_tensor=True,
                    normalize_embeddings=True
                ).cpu().numpy()
                
            # Ensure query embedding has the correct dimensions (TEXT_EMBEDDING_DIM)
            if query_embedding is not None and query_embedding.shape[0] != config.TEXT_EMBEDDING_DIM:
                logging.warning(f"Query embedding dimension mismatch: got {query_embedding.shape[0]}, expected {config.TEXT_EMBEDDING_DIM}")
                
                try:
                    if query_embedding.shape[0] < config.TEXT_EMBEDDING_DIM:
                        # Pad with zeros if smaller
                        padding = np.zeros(config.TEXT_EMBEDDING_DIM - query_embedding.shape[0], dtype=np.float32)
                        query_embedding = np.concatenate([query_embedding, padding])
                    else:
                        # Truncate if larger
                        query_embedding = query_embedding[:config.TEXT_EMBEDDING_DIM]
                    
                    # Normalize the vector after resizing
                    query_embedding = query_embedding / np.linalg.norm(query_embedding)
                    logging.info(f"Resized query embedding to {config.TEXT_EMBEDDING_DIM} dimensions")
                except Exception as resize_error:
                    logging.error(f"Failed to resize query embedding: {str(resize_error)}")

        # Search Redis for matching memes
        results = []
        if query:
            # Get all meme keys
            meme_keys = redis_client.keys("meme:*")
            logging.info(f"Found {len(meme_keys)} total meme keys to search")
            
            # Normalize query for text matching
            normalized_query = normalize_arabic(query.lower())
            
            for meme_key in meme_keys:
                try:
                    meme_data = redis_client.hgetall(meme_key)
                    if not meme_data:
                        continue

                    # Check meme type if filter is applied
                    if meme_type and meme_data.get(b'meme_type', b'').decode() != meme_type:
                        continue

                    # Get tags and caption
                    tags_json = meme_data.get(b'tags', b'[]').decode()
                    try:
                        tags = json.loads(tags_json)
                        # Handle both array and object formats for tags
                        if isinstance(tags, dict):
                            all_tags = []
                            for tag_list in tags.values():
                                if isinstance(tag_list, list):
                                    all_tags.extend(tag_list)
                            tags = all_tags
                    except:
                        tags = []
                    
                    caption = meme_data.get(b'caption', b'').decode()
                    
                    # For Arabic queries, prioritize tag and caption text matching over vector similarity
                    if has_arabic:
                        # Calculate direct tag matches for Arabic
                        tag_scores = []
                        exact_match_found = False
                        
                        for tag in tags:
                            # Normalize tag for Arabic comparison
                            normalized_tag = normalize_arabic(tag.lower())
                            
                            # Check for exact/strong matches
                            if normalized_query == normalized_tag or normalized_query in normalized_tag or normalized_tag in normalized_query:
                                tag_scores.append(1.0)
                                exact_match_found = True
                                logging.info(f"Exact match found for '{query}' in tag '{tag}'")
                                break
                            
                            # Calculate similarity
                            tag_sim = text_similarity(normalized_query, normalized_tag)
                            tag_scores.append(tag_sim)
                        
                        # Calculate caption match score
                        normalized_caption = normalize_arabic(caption.lower())
                        caption_score = text_similarity(normalized_query, normalized_caption)
                        
                        # If we have a near-exact match in tags, boost the score significantly
                        if exact_match_found:
                            text_match_score = 1.0
                            semantic_similarity = 1.0  # Override semantic score for exact matches
                        else:
                            # Get best tag match score
                            best_tag_score = max(tag_scores) if tag_scores else 0
                            
                            # Calculate text match score giving more weight to tags for Arabic
                            text_match_score = max(
                                caption_score * 0.4,  # Less weight for caption
                                best_tag_score * 0.9   # More weight for tags in Arabic
                            )
                            
                            # Calculate vector similarity if available and no exact match found
                            semantic_similarity = 0.0
                            if query_embedding is not None and not exact_match_found and b'text_vector' in meme_data:
                                text_vector = np.frombuffer(meme_data[b'text_vector'], dtype=np.float32)
                                
                                # Handle dimension mismatch between query and stored vectors
                                if text_vector.shape[0] != query_embedding.shape[0]:
                                    try:
                                        # Resize the stored vector to match query dimensions
                                        if text_vector.shape[0] < query_embedding.shape[0]:
                                            # Pad with zeros if smaller
                                            padding = np.zeros(query_embedding.shape[0] - text_vector.shape[0], dtype=np.float32)
                                            text_vector = np.concatenate([text_vector, padding])
                                        else:
                                            # Truncate if larger
                                            text_vector = text_vector[:query_embedding.shape[0]]
                                            
                                        # Normalize after resizing
                                        text_vector = text_vector / np.linalg.norm(text_vector)
                                    except Exception as resize_error:
                                        logging.error(f"Failed to resize vector for {meme_key}: {str(resize_error)}")
                                        continue
                                
                                semantic_similarity = cosine_similarity(query_embedding, text_vector)
                        
                        # For Arabic, prioritize text matching but still consider semantic similarity
                        # However for Arabic, we should weight exact/close matches more heavily
                        final_score = text_match_score * 0.8 + (semantic_similarity * 0.2 if semantic_similarity > 0 else 0)
                        
                        # Boost score if text match is strong
                        if text_match_score > 0.7:
                            final_score = max(final_score, 0.8)  # Ensure high-relevance results get high scores
                        
                        boost = 0.0  # For clarity in the API response
                    else:
                        # For non-Arabic queries, use the balanced approach
                        # Calculate vector similarity if available
                        semantic_similarity = 0.0
                        if query_embedding is not None and b'text_vector' in meme_data:
                            text_vector = np.frombuffer(meme_data[b'text_vector'], dtype=np.float32)
                            
                            # Handle dimension mismatch between query and stored vectors
                            if text_vector.shape[0] != query_embedding.shape[0]:
                                try:
                                    # Resize the stored vector to match query dimensions
                                    if text_vector.shape[0] < query_embedding.shape[0]:
                                        # Pad with zeros if smaller
                                        padding = np.zeros(query_embedding.shape[0] - text_vector.shape[0], dtype=np.float32)
                                        text_vector = np.concatenate([text_vector, padding])
                                    else:
                                        # Truncate if larger
                                        text_vector = text_vector[:query_embedding.shape[0]]
                                        
                                    # Normalize after resizing
                                    text_vector = text_vector / np.linalg.norm(text_vector)
                                except Exception as resize_error:
                                    logging.error(f"Failed to resize vector for {meme_key}: {str(resize_error)}")
                                    continue
                            
                            semantic_similarity = cosine_similarity(query_embedding, text_vector)
                        
                        # Calculate text similarity scores
                        tag_scores = []
                        for tag in tags:
                            tag_sim = text_similarity(query, tag)
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
                        
                        # Final score balances text matches and semantic similarity
                        final_score = max(
                            text_match_score * 0.6 + semantic_similarity * 0.4,  # Text-focused score
                            semantic_similarity * 0.6 + text_match_score * 0.4   # Semantic-focused score
                        ) + boost
                        
                        # Ensure very relevant results get high scores
                        if text_match_score > 0.8 or semantic_similarity > 0.8:
                            final_score = max(final_score, 0.9)  # Minimum score for highly relevant results
                    
                    # Get meme ID from key
                    meme_id = int(meme_key.decode().split(':')[1])
                    
                    # Only include results with scores above a minimum threshold
                    # Set a higher threshold for Arabic to ensure better quality
                    min_threshold = 0.3 if has_arabic else 0.2
                    
                    if final_score >= min_threshold:
                        results.append({
                            'id': meme_id,
                            'score': float(final_score),
                            'semantic_similarity': float(semantic_similarity),
                            'text_match_score': float(text_match_score),
                            'caption_score': float(caption_score) if 'caption_score' in locals() else 0.0,
                            'best_tag_score': float(best_tag_score) if 'best_tag_score' in locals() else 0.0,
                            'boost': float(boost) if 'boost' in locals() else 0.0,
                            'has_exact_match': exact_match_found if 'exact_match_found' in locals() else False
                        })
                except Exception as e:
                    logging.error(f"Error processing meme {meme_key}: {str(e)}")
                    continue
        
        # Sort by score in descending order and limit results
        results.sort(key=lambda x: x['score'], reverse=True)
        results = results[:limit]
        
        logging.info(f"Found {len(results)} matching results for query '{query}'")
        
        return jsonify({
            'results': results
        }), 200
        
    except Exception as e:
        logging.error(f"Error in text search: {str(e)}")
        return jsonify({'error': str(e)}), 500

@app.route('/search_by_text', methods=['POST'])
def search_by_text():
    """Search for memes using semantic relationships from text embeddings"""
    try:
        data = request.get_json()
        if not data or 'query' not in data:
            return jsonify({'error': 'Missing query'}), 400

        query = data['query']
        limit = int(data.get('limit', 10))
        
        logging.info(f"Semantic search for query: {query}")
        
        # Generate query embedding - this is the key to finding related content
        try:
            query_embedding = get_text_embedding(query)
            logging.info(f"Generated query embedding with shape: {query_embedding.shape}")
            
            # Normalize the embedding for better comparisons
            query_embedding = query_embedding / np.linalg.norm(query_embedding)
        except Exception as e:
            logging.error(f"Error generating query embedding: {str(e)}")
            return jsonify({
                'status': 'error',
                'message': f"Error generating query embedding: {str(e)}"
            }), 500

        # Get all meme keys
        all_keys = redis_client.keys('meme:*')
        logging.info(f"Found {len(all_keys)} meme keys in Redis")
        
        # Process results with ONLY semantic similarity
        meme_results = []
        
        for meme_key in all_keys:
            try:
                # Get meme data from Redis hash
                meme_data = redis_client.hgetall(meme_key)
                
                if not meme_data or b'text_vector' not in meme_data:
                    continue
                
                # Get meme ID from key
                meme_id = int(meme_key.decode().split(':')[1])
                
                # Calculate vector similarity score - ONLY using vector similarity
                doc_vector = np.frombuffer(meme_data[b'text_vector'], dtype=np.float32)
                
                # Handle dimension mismatch
                if doc_vector.shape[0] != query_embedding.shape[0]:
                    try:
                        # Resize the stored vector to match query dimensions
                        if doc_vector.shape[0] < query_embedding.shape[0]:
                            # Pad with zeros if smaller
                            padding = np.zeros(query_embedding.shape[0] - doc_vector.shape[0], dtype=np.float32)
                            doc_vector = np.concatenate([doc_vector, padding])
                        else:
                            # Truncate if larger
                            doc_vector = doc_vector[:query_embedding.shape[0]]
                        
                        # Normalize after resizing
                        doc_vector = doc_vector / np.linalg.norm(doc_vector)
                    except Exception as resize_error:
                        logging.error(f"Failed to resize vector for {meme_key}: {str(resize_error)}")
                        continue
                
                # Calculate pure semantic similarity score
                similarity_score = cosine_similarity(query_embedding, doc_vector)
                
                # Only include results with decent semantic similarity
                # Using a lower threshold for Arabic to ensure we get results
                min_similarity = 0.4
                
                if similarity_score >= min_similarity:
                    meme_results.append({
                        'id': int(meme_id),
                        'score': float(similarity_score)
                    })
                
            except Exception as e:
                logging.warning(f"Error processing result doc {meme_key}: {str(e)}")
                continue
        
        # Sort by score
        meme_results.sort(key=lambda x: x['score'], reverse=True)
        
        # Limit results
        meme_results = meme_results[:limit]
        
        logging.info(f"Returning {len(meme_results)} semantic search results")

        return jsonify({
            'status': 'success',
            'results': meme_results
        })

    except Exception as e:
        logging.error(f"Search error: {str(e)}")
        return jsonify({'status': 'error', 'message': str(e)}), 500

def setup_redis_index():
    """Create Redis search indices for hybrid search"""
    try:
        # Try to drop index if it exists
        try:
            redis_client.ft(config.REDIS_INDEX_NAME).dropindex()
            logging.info(f"Dropped existing Redis index: {config.REDIS_INDEX_NAME}")
        except:
            logging.info(f"No existing index to drop: {config.REDIS_INDEX_NAME}")

        # Define schema
        schema = (
            TextField("id"),
            TextField("caption", weight=5.0),
            TextField("text_content", weight=2.0),
            TextField("type"),
            TagField("tags"),
            VectorField("img_vector", 
                        "HNSW", {
                            "TYPE": "FLOAT32",
                            "DIM": config.CLIP_EMBEDDING_DIM,
                            "DISTANCE_METRIC": "COSINE"
                        }),
            VectorField("text_vector", 
                        "HNSW", {
                            "TYPE": "FLOAT32",
                            "DIM": config.TEXT_EMBEDDING_DIM,
                            "DISTANCE_METRIC": "COSINE"
                        }),
            TextField("emotions")
        )

        # Create index
        redis_client.ft(config.REDIS_INDEX_NAME).create_index(
            schema,
            definition=IndexDefinition(prefix=["meme:"])
        )
        logging.info(f"Redis search index created successfully with dimensions: CLIP={config.CLIP_EMBEDDING_DIM}, TEXT={config.TEXT_EMBEDDING_DIM}")
    except Exception as e:
        logging.error(f"Error setting up Redis index: {str(e)}")
        raise

@app.route('/rebuild_index', methods=['POST'])
def rebuild_index():
    """Rebuild the Redis search index and mark all memes for reindexing"""
    try:
        # First recreate the Redis index
        setup_redis_index()
        
        # Clear all dimension mismatch issues by marking memes for reindexing
        meme_keys = redis_client.keys("meme:*")
        total = len(meme_keys)
        processed = 0
        mismatch_count = 0
        
        for meme_key in meme_keys:
            try:
                # Check if the vector dimensions match the expected dimensions
                meme_data = redis_client.hgetall(meme_key)
                
                if b'text_vector' in meme_data:
                    text_vector = np.frombuffer(meme_data[b'text_vector'], dtype=np.float32)
                    
                    # If dimensions don't match the current config, mark for reindexing
                    if text_vector.shape[0] != config.TEXT_EMBEDDING_DIM:
                        # Extract ID from key
                        meme_id = meme_key.decode('utf-8').split(':')[1]
                        logging.info(f"Marking meme {meme_id} for reindexing due to dimension mismatch: {text_vector.shape[0]} != {config.TEXT_EMBEDDING_DIM}")
                        
                        # Add to list of memes that need reindexing
                        redis_client.sadd('memes:reindex', meme_id)
                        mismatch_count += 1
                
                if b'img_vector' in meme_data:
                    img_vector = np.frombuffer(meme_data[b'img_vector'], dtype=np.float32)
                    
                    # If dimensions don't match the current config, mark for reindexing
                    if img_vector.shape[0] != config.CLIP_EMBEDDING_DIM:
                        # Extract ID from key
                        meme_id = meme_key.decode('utf-8').split(':')[1]
                        logging.info(f"Marking meme {meme_id} for reindexing due to image dimension mismatch: {img_vector.shape[0]} != {config.CLIP_EMBEDDING_DIM}")
                        
                        # Add to list of memes that need reindexing
                        redis_client.sadd('memes:reindex', meme_id)
                        mismatch_count += 1
                
                processed += 1
                if processed % 100 == 0:
                    logging.info(f"Processed {processed}/{total} memes")
                
            except Exception as e:
                logging.error(f"Error processing meme {meme_key} for reindex marking: {str(e)}")
                continue
        
        return jsonify({
            'status': 'success',
            'message': f'Redis index rebuilt with TEXT_EMBEDDING_DIM={config.TEXT_EMBEDDING_DIM}, CLIP_EMBEDDING_DIM={config.CLIP_EMBEDDING_DIM}',
            'memes_processed': processed,
            'dimension_mismatches': mismatch_count,
            'memes_to_reindex': redis_client.scard('memes:reindex')
        }), 200
    
    except Exception as e:
        logging.error(f"Error rebuilding index: {str(e)}")
        return jsonify({'error': str(e)}), 500

def get_or_generate_image_embedding(meme_id, content_url):
    """Get image embedding or generate if needed"""
    try:
        # Try to get cached image embedding
        cache_key = f"meme:{meme_id}:image_embedding"
        cached = redis_client.get(cache_key)
        if cached:
            embedding = np.frombuffer(cached, dtype=np.float32)
            
            # Validate dimensions
            if embedding.shape[0] == config.CLIP_EMBEDDING_DIM:
                return embedding
            
            logging.warning(f"Stored image embedding for meme {meme_id} has incorrect dimensions: {embedding.shape[0]}")
        
        # Generate new embedding
        logging.info(f"Generating new image embedding for meme {meme_id}")
        embedding = get_image_embedding(content_url)
        
        if embedding is not None:
            # Ensure embedding is 1D and has correct shape
            if len(embedding.shape) > 1:
                logging.warning(f"Flattening image embedding with shape {embedding.shape}")
                embedding = embedding.flatten()
                
            # Ensure correct dimensions
            if embedding.shape[0] != config.CLIP_EMBEDDING_DIM:
                logging.warning(f"Resizing embedding from {embedding.shape[0]} to {config.CLIP_EMBEDDING_DIM}")
                if embedding.shape[0] < config.CLIP_EMBEDDING_DIM:
                    # Pad with zeros (ensure padding is 1D)
                    padding_size = config.CLIP_EMBEDDING_DIM - embedding.shape[0]
                    padding = np.zeros(padding_size, dtype=np.float32)
                    logging.info(f"Adding padding with shape {padding.shape} to embedding with shape {embedding.shape}")
                    embedding = np.concatenate([embedding, padding])
                else:
                    # Truncate
                    embedding = embedding[:config.CLIP_EMBEDDING_DIM]
                    
            # Normalize
            norm = np.linalg.norm(embedding)
            if norm > 0:
                embedding = embedding / norm
                
            # Cache the embedding
            redis_client.set(cache_key, embedding.tobytes())
            return embedding
        else:
            logging.error(f"Failed to generate image embedding for meme {meme_id}")
            # Return zero vector as fallback
            return np.zeros(config.CLIP_EMBEDDING_DIM, dtype=np.float32)
            
    except Exception as e:
        logging.error(f"Error in get_or_generate_image_embedding for meme {meme_id}: {str(e)}")
        return np.zeros(config.CLIP_EMBEDDING_DIM, dtype=np.float32)

if __name__ == '__main__':
    # Initialize Redis index
    setup_redis_index()
    app.run(host=config.SERVER_HOST, port=config.SEARCH_PORT)
