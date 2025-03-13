"""Redis client for vector search and caching."""

import json
import logging
import redis
from redis.commands.search.field import VectorField, TagField, TextField
from typing import Dict, List, Optional

from config import (
    REDIS_HOST, REDIS_PORT, REDIS_SEARCH_DB, EMBEDDING_DIM,
    REDIS_INDEX_NAME, DEFAULT_SEARCH_LIMIT,
    VECTOR_SCORE_WEIGHT, EMOTION_SCORE_WEIGHT
)

class RedisClient:
    """Handles Redis operations for meme storage and search."""
    
    def __init__(self):
        """Initialize Redis client and create search index."""
        self.client = redis.Redis(
            host=REDIS_HOST,
            port=REDIS_PORT,
            db=REDIS_SEARCH_DB
        )
        self._create_search_index()
    
    def _create_search_index(self) -> None:
        """Create Redis search index with vector similarity."""
        try:
            schema = (
                VectorField(
                    "embedding",
                    "HNSW",
                    {
                        "TYPE": "FLOAT32",
                        "DIM": EMBEDDING_DIM,
                        "DISTANCE_METRIC": "COSINE"
                    }
                ),
                TagField("type"),  # image or video
                TextField("text_content"),  # Any text content in the meme
                TextField("emotions")  # Emotion analysis results
            )
            
            self.client.ft(REDIS_INDEX_NAME).create_index(schema)
        except Exception as e:
            logging.warning(f"Index might already exist: {str(e)}")
    
    def store_meme(self, meme_id: str, meme_data: Dict) -> bool:
        """Store meme data in Redis.
        
        Args:
            meme_id: Unique identifier for the meme
            meme_data: Dictionary containing meme data
            
        Returns:
            True if successful, False otherwise
        """
        try:
            self.client.hset(f"meme:{meme_id}", mapping=meme_data)
            return True
        except Exception as e:
            logging.error(f"Error storing meme {meme_id}: {str(e)}")
            return False
    
    def search_memes(
        self,
        query_embedding: np.ndarray,
        expanded_queries: List[str],
        limit: int = DEFAULT_SEARCH_LIMIT
    ) -> List[Dict]:
        """Search for memes using vector similarity and text search.
        
        Args:
            query_embedding: Vector embedding of the search query
            expanded_queries: List of expanded text queries
            limit: Maximum number of results to return
            
        Returns:
            List of matching memes with scores
        """
        try:
            # Search parameters
            search_params = {
                "DIALECT": 3,  # Use dialect 3 for vector similarity
                "RETURN": ["type", "text_content", "emotions"],
                "SORTBY": "__vector_score",
                "LIMIT": 0, limit
            }
            
            # Combine vector search with text search
            base_query = f"(@type:(image|video))"
            text_conditions = [
                f"(@text_content:{query})" for query in expanded_queries
            ]
            
            if text_conditions:
                text_query = " | ".join(text_conditions)  # OR condition
                base_query = f"({base_query} | {text_query})"
            
            # Create hybrid query
            vector_query = self.client.ft(REDIS_INDEX_NAME).create_query(
                base_query,
                query_params={
                    "vec": query_embedding.astype(np.float32).tobytes()
                },
                **search_params
            )
            
            # Execute search
            results = self.client.ft(REDIS_INDEX_NAME).search(vector_query)
            
            # Process results
            memes = []
            for doc in results.docs:
                meme_id = doc.id.split(":")[-1]
                emotions = json.loads(doc.emotions)
                
                # Calculate hybrid score
                base_score = 1 - float(doc.vector_score)  # Convert distance to similarity
                emotion_score = (
                    sum(emotions.values()) / len(emotions) if emotions else 0
                )
                final_score = (
                    VECTOR_SCORE_WEIGHT * base_score +
                    EMOTION_SCORE_WEIGHT * emotion_score
                )
                
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
