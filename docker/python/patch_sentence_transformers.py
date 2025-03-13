#!/usr/bin/env python3
"""
This script patches sentence-transformers to work with newer huggingface-hub versions
by adding a compatibility layer for the cached_download function.
"""

import os
import sys
import inspect
import importlib
from pathlib import Path

def find_sentence_transformers_path():
    """Find the installation path of sentence_transformers module"""
    try:
        import sentence_transformers
        return os.path.dirname(inspect.getfile(sentence_transformers))
    except ImportError:
        print("sentence_transformers module not found. Please install it first.")
        sys.exit(1)

def patch_sentence_transformer():
    """Patch SentenceTransformer.py to use hf_hub_download instead of cached_download"""
    st_path = find_sentence_transformers_path()
    transformer_path = os.path.join(st_path, "SentenceTransformer.py")
    
    if not os.path.exists(transformer_path):
        print(f"Could not find {transformer_path}")
        return False
    
    # Backup the original file
    backup_path = transformer_path + ".bak"
    if not os.path.exists(backup_path):
        print(f"Creating backup at {backup_path}")
        with open(transformer_path, "r", encoding="utf-8") as src:
            with open(backup_path, "w", encoding="utf-8") as dst:
                dst.write(src.read())
    
    # Read the original content
    with open(transformer_path, "r", encoding="utf-8") as f:
        content = f.read()
    
    # Check if already patched
    if "# PATCHED FOR HF HUB COMPATIBILITY" in content:
        print("File already patched.")
        return True
    
    # Add compatibility patch for cached_download
    if "from huggingface_hub import HfApi, HfFolder, Repository, hf_hub_url, cached_download" in content:
        # Add a compatibility function for cached_download
        patched_content = content.replace(
            "from huggingface_hub import HfApi, HfFolder, Repository, hf_hub_url, cached_download",
            """from huggingface_hub import HfApi, HfFolder, Repository, hf_hub_url
# PATCHED FOR HF HUB COMPATIBILITY
from huggingface_hub import hf_hub_download
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
                return hf_hub_download(repo_id=repo_id, filename=filename, cache_dir=cache_dir)
        # Fallback for non-HF URLs
        return url
    except Exception as e:
        print(f"Error in cached_download: {e}")
        return url"""
        )
        
        # Write the patched content
        with open(transformer_path, "w", encoding="utf-8") as f:
            f.write(patched_content)
        
        print(f"Successfully patched {transformer_path}")
        return True
    else:
        print("Could not find the import line to patch.")
        return False

if __name__ == "__main__":
    result = patch_sentence_transformer()
    if result:
        print("Patch completed successfully. Try running your script again.")
    else:
        print("Patch could not be applied. Manual intervention required.")
