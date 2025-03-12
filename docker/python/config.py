"""Configuration settings for the meme search engine Python services.

This module loads configuration from .env files using python-dotenv.
Environment variables take precedence over default values.
"""

import os
import torch
from dotenv import load_dotenv

# Load environment variables from .env file
load_dotenv()

# Device configuration
DEVICE = os.getenv("PYTHON_DEVICE", "cuda" if torch.cuda.is_available() else "cpu")

# Model configurations
EMBEDDING_DIM = int(os.getenv("EMBEDDING_DIM", "1024"))  # Default: LLaVA embedding dimension
LLAVA_MODEL = os.getenv("LLAVA_MODEL", "llava-hf/llava-1.5-7b-hf")
TEXT_MODEL = os.getenv("TEXT_MODEL", "intfloat/multilingual-e5-large")
STEMMER_MODEL = os.getenv("STEMMER_MODEL", "sentence-transformers/paraphrase-multilingual-mpnet-base-v2")
CLIP_MODEL = os.getenv("CLIP_MODEL", "ViT-B/32")
CLIP_EMBEDDING_DIM = int(os.getenv("CLIP_EMBEDDING_DIM", "512"))
TEXT_EMBEDDING_DIM = int(os.getenv("TEXT_EMBEDDING_DIM", "768"))

# Processing configurations
BATCH_SIZE = int(os.getenv("BATCH_SIZE", "32"))
VIDEO_FRAME_COUNT = int(os.getenv("VIDEO_FRAME_COUNT", "5"))
VIDEO_TARGET_DURATION = int(os.getenv("VIDEO_TARGET_DURATION", "3"))  # seconds to analyze for videos

# Redis configurations
REDIS_HOST = os.getenv("REDIS_HOST", "host.docker.internal")
REDIS_PORT = int(os.getenv("REDIS_PORT", "6379"))
REDIS_DB = int(os.getenv("REDIS_DB", "0"))
REDIS_PASSWORD = os.getenv("REDIS_PASSWORD", None)
REDIS_SSL = os.getenv("REDIS_SSL", "False").lower() in ("true", "1", "yes")
REDIS_MAX_CONNECTIONS = int(os.getenv("REDIS_MAX_CONNECTIONS", "20"))
CACHE_TTL = int(os.getenv("CACHE_TTL", "86400"))  # 24 hours
REDIS_INDEX_NAME = os.getenv("REDIS_INDEX_NAME", "meme_idx")

# Server configurations
STEMMER_PORT = int(os.getenv("STEMMER_PORT", "5050"))
SEARCH_PORT = int(os.getenv("SEARCH_PORT", "5051"))
SERVER_HOST = os.getenv("SERVER_HOST", "0.0.0.0")

# Search configurations
DEFAULT_SEARCH_LIMIT = int(os.getenv("DEFAULT_SEARCH_LIMIT", "20"))
VECTOR_SCORE_WEIGHT = float(os.getenv("VECTOR_SCORE_WEIGHT", "0.8"))
EMOTION_SCORE_WEIGHT = float(os.getenv("EMOTION_SCORE_WEIGHT", "0.2"))

# Arabic meme-related terms
ARABIC_MEME_TERMS = os.getenv("ARABIC_MEME_TERMS", "ميم,مضحك,تحشيش").split(",")

# Debug mode
DEBUG = os.getenv("DEBUG", "False").lower() in ("true", "1", "yes")
