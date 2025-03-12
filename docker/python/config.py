"""Configuration settings for the search service."""

import torch

# Device configuration
DEVICE = "cuda" if torch.cuda.is_available() else "cpu"

# Model configurations
EMBEDDING_DIM = 1024  # LLaVA embedding dimension
LLAVA_MODEL = "llava-hf/llava-1.5-7b-hf"
TEXT_MODEL = "intfloat/multilingual-e5-large"

# Processing configurations
BATCH_SIZE = 32
VIDEO_FRAME_COUNT = 5
VIDEO_TARGET_DURATION = 3  # seconds to analyze for videos

# Redis configurations
REDIS_HOST = "localhost"
REDIS_PORT = 6379
REDIS_DB = 0
CACHE_TTL = 86400  # 24 hours
REDIS_INDEX_NAME = "meme_idx"

# Search configurations
DEFAULT_SEARCH_LIMIT = 20
VECTOR_SCORE_WEIGHT = 0.8
EMOTION_SCORE_WEIGHT = 0.2

# Arabic meme-related terms
ARABIC_MEME_TERMS = ["ميم", "مضحك", "تحشيش"]
