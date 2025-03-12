"""Multimodal encoder models for meme processing."""

import logging
import numpy as np
import torch
from PIL import Image
from typing import List, Dict
from transformers import AutoModel, AutoProcessor
from sentence_transformers import SentenceTransformer
from deepface import DeepFace

from config import (
    DEVICE, EMBEDDING_DIM, LLAVA_MODEL, TEXT_MODEL,
    ARABIC_MEME_TERMS
)

class MultimodalEncoder:
    """Handles encoding of images, videos, and text for meme processing."""
    
    def __init__(self):
        """Initialize the encoder models."""
        self._init_models()
        self._move_to_device()
        self._set_eval_mode()
    
    def _init_models(self):
        """Initialize all required models."""
        # Visual understanding model (better than CLIP for Arabic)
        self.llava_model = AutoModel.from_pretrained(LLAVA_MODEL, trust_remote_code=True)
        self.llava_processor = AutoProcessor.from_pretrained(LLAVA_MODEL)
        
        # Text encoder with Arabic support
        self.text_encoder = SentenceTransformer(TEXT_MODEL)
        
        # Emotion analyzer
        self.emotion_analyzer = DeepFace
    
    def _move_to_device(self):
        """Move models to appropriate device (CPU/GPU)."""
        self.llava_model.to(DEVICE)
        self.text_encoder.to(DEVICE)
    
    def _set_eval_mode(self):
        """Set models to evaluation mode."""
        self.llava_model.eval()
        self.text_encoder.eval()
    
    @torch.no_grad()
    def encode_image(self, image: Image.Image) -> np.ndarray:
        """Generate embeddings for an image using LLaVA.
        
        Args:
            image: PIL Image to encode
            
        Returns:
            Normalized embedding vector as numpy array
        """
        try:
            # Process image
            inputs = self.llava_processor(images=image, return_tensors="pt").to(DEVICE)
            
            # Get embeddings
            outputs = self.llava_model.get_image_features(**inputs)
            embedding = outputs.detach().cpu().numpy().mean(axis=1)  # Average pooling
            
            # Normalize
            embedding = embedding / np.linalg.norm(embedding)
            return embedding.astype(np.float32)
            
        except Exception as e:
            logging.error(f"Error encoding image: {str(e)}")
            return np.zeros(EMBEDDING_DIM, dtype=np.float32)

    def encode_text(self, text: str) -> np.ndarray:
        """Generate embeddings for text using multilingual E5.
        
        Args:
            text: Text to encode
            
        Returns:
            Normalized embedding vector as numpy array
        """
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
        """Analyze emotions in an image using DeepFace.
        
        Args:
            image: PIL Image to analyze
            
        Returns:
            Dictionary of emotion scores
        """
        try:
            # Convert PIL to numpy array
            img_array = np.array(image)
            
            # Analyze emotions
            analysis = self.emotion_analyzer.analyze(
                img_array, 
                actions=['emotion'],
                enforce_detection=False
            )
            
            return analysis[0]['emotion'] if analysis else {}
            
        except Exception as e:
            logging.error(f"Error analyzing emotions: {str(e)}")
            return {}

    def expand_query(self, query: str) -> List[str]:
        """Expand query with Arabic support.
        
        Args:
            query: Original search query
            
        Returns:
            List of expanded queries
        """
        expanded_queries = [query]
        
        # Add Arabic-specific expansions
        if any(ord(c) in range(0x0600, 0x06FF) for c in query):  # If contains Arabic
            # Add common Arabic meme-related terms
            expanded_queries.extend([
                f"{query} {term}" for term in ARABIC_MEME_TERMS
            ])
        
        return expanded_queries
