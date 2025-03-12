"""Video processing utilities for meme search service."""

import cv2
import logging
import m3u8
import numpy as np
import os
import requests
import subprocess
import tempfile
from PIL import Image
from typing import List, Optional
from urllib.parse import urlparse, urljoin

from config import VIDEO_FRAME_COUNT, VIDEO_TARGET_DURATION

def get_base_url(url: str) -> str:
    """Extract base URL for resolving relative paths.
    
    Args:
        url: Full URL to parse
        
    Returns:
        Base URL for resolving relative paths
    """
    parsed = urlparse(url)
    path_parts = parsed.path.rsplit('/', 1)[0] if '/' in parsed.path else ''
    return f"{parsed.scheme}://{parsed.netloc}{path_parts}/"

def extract_video_frames(video_url: str, num_frames: int = VIDEO_FRAME_COUNT) -> Optional[List[Image.Image]]:
    """Extract key frames from a video URL supporting multiple formats.
    
    Args:
        video_url: URL of the video
        num_frames: Number of frames to extract
        
    Returns:
        List of PIL Images or None if extraction fails
    """
    try:
        with tempfile.TemporaryDirectory() as temp_dir:
            output_path = os.path.join(temp_dir, "video.mp4")
            
            # Download video file or segments
            if video_url.endswith('.m3u8'):
                frames = _process_hls_stream(video_url, temp_dir, output_path)
            else:
                frames = _process_direct_video(video_url, temp_dir, output_path)
            
            return frames
            
    except Exception as e:
        logging.error(f"Error extracting video frames from {video_url}: {str(e)}")
        return None

def _process_hls_stream(video_url: str, temp_dir: str, output_path: str) -> Optional[List[Image.Image]]:
    """Process HLS stream and extract frames.
    
    Args:
        video_url: URL of the HLS stream
        temp_dir: Temporary directory for processing
        output_path: Path to save the processed video
        
    Returns:
        List of PIL Images or None if processing fails
    """
    try:
        base_url = get_base_url(video_url)
        logging.info(f"Base URL for HLS: {base_url}")
        
        playlist = m3u8.load(video_url)
        if not playlist.segments and playlist.playlists:
            playlist = _get_highest_quality_playlist(playlist, base_url)
            base_url = get_base_url(playlist.uri)
        
        if not playlist.segments:
            raise ValueError("No segments found in playlist")
        
        segment_files = _download_segments(playlist, base_url, temp_dir)
        if not segment_files:
            raise ValueError("No segments downloaded")
        
        _concatenate_segments(segment_files, temp_dir, output_path)
        return _extract_frames_from_video(output_path)
        
    except Exception as e:
        logging.error(f"HLS processing error: {str(e)}")
        return None

def _get_highest_quality_playlist(master_playlist: m3u8.M3U8, base_url: str) -> m3u8.M3U8:
    """Get the highest quality variant playlist.
    
    Args:
        master_playlist: Master HLS playlist
        base_url: Base URL for resolving relative paths
        
    Returns:
        Highest quality variant playlist
    """
    playlists = sorted(
        master_playlist.playlists,
        key=lambda p: p.stream_info.resolution[0] if p.stream_info and p.stream_info.resolution else (0, 0),
        reverse=True
    )
    variant_url = urljoin(base_url, playlists[0].uri)
    logging.info(f"Loading variant playlist: {variant_url}")
    return m3u8.load(variant_url)

def _download_segments(playlist: m3u8.M3U8, base_url: str, temp_dir: str) -> List[str]:
    """Download HLS segments.
    
    Args:
        playlist: HLS playlist
        base_url: Base URL for resolving relative paths
        temp_dir: Directory to save segments
        
    Returns:
        List of paths to downloaded segment files
    """
    segment_files = []
    total_duration = 0
    
    for segment in playlist.segments:
        if total_duration >= VIDEO_TARGET_DURATION:
            break
        
        segment_url = urljoin(base_url, segment.uri)
        logging.info(f"Downloading segment: {segment_url}")
        
        segment_path = os.path.join(temp_dir, f"segment_{len(segment_files)}.ts")
        response = requests.get(segment_url, timeout=10)
        response.raise_for_status()
        
        with open(segment_path, 'wb') as f:
            f.write(response.content)
        
        segment_files.append(segment_path)
        total_duration += segment.duration
    
    return segment_files

def _concatenate_segments(segment_files: List[str], temp_dir: str, output_path: str) -> None:
    """Concatenate HLS segments into a single video file.
    
    Args:
        segment_files: List of segment file paths
        temp_dir: Temporary directory for processing
        output_path: Path to save the concatenated video
    """
    concat_list = os.path.join(temp_dir, "concat.txt")
    with open(concat_list, 'w') as f:
        for file in segment_files:
            f.write(f"file '{file}'\n")
    
    cmd = [
        'ffmpeg', '-f', 'concat', '-safe', '0',
        '-i', concat_list,
        '-c', 'copy',  # Just copy streams without re-encoding
        '-y', output_path
    ]
    
    _run_ffmpeg_command(cmd)

def _process_direct_video(video_url: str, temp_dir: str, output_path: str) -> Optional[List[Image.Image]]:
    """Process direct video URL and extract frames.
    
    Args:
        video_url: URL of the video
        temp_dir: Temporary directory for processing
        output_path: Path to save the processed video
        
    Returns:
        List of PIL Images or None if processing fails
    """
    try:
        video_path = os.path.join(temp_dir, "input_video")
        
        # Download video
        response = requests.get(video_url, stream=True, timeout=10)
        response.raise_for_status()
        
        with open(video_path, 'wb') as f:
            for chunk in response.iter_content(chunk_size=8192):
                if chunk:
                    f.write(chunk)
        
        # Convert video
        cmd = [
            'ffmpeg', '-i', video_path,
            '-c', 'copy',  # Just copy streams without re-encoding
            '-y', output_path
        ]
        
        _run_ffmpeg_command(cmd)
        return _extract_frames_from_video(output_path)
        
    except Exception as e:
        logging.error(f"Direct video processing error: {str(e)}")
        return None

def _run_ffmpeg_command(cmd: List[str]) -> None:
    """Run ffmpeg command with error handling.
    
    Args:
        cmd: ffmpeg command as list of arguments
    """
    try:
        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.returncode != 0:
            error_msg = result.stderr.strip() if result.stderr else "Unknown ffmpeg error"
            logging.error(f"FFmpeg command failed: {' '.join(cmd)}")
            logging.error(f"FFmpeg error output: {error_msg}")
            raise ValueError(f"FFmpeg error: {error_msg}")
    except Exception as e:
        logging.error(f"Error running ffmpeg: {str(e)}")
        raise

def _extract_frames_from_video(video_path: str) -> List[Image.Image]:
    """Extract frames from video file using OpenCV.
    
    Args:
        video_path: Path to video file
        
    Returns:
        List of PIL Images
    """
    cap = cv2.VideoCapture(video_path)
    if not cap.isOpened():
        raise ValueError("OpenCV failed to open the video file")
    
    total_frames = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    if total_frames == 0:
        raise ValueError("No frames found in the video")
    
    # Calculate frame indices for key frames
    frame_indices = np.linspace(0, total_frames-1, VIDEO_FRAME_COUNT, dtype=int)
    frames = []
    
    for idx in frame_indices:
        cap.set(cv2.CAP_PROP_POS_FRAMES, idx)
        ret, frame = cap.read()
        if ret:
            # Convert BGR to RGB
            frame_rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
            # Convert to PIL Image
            pil_image = Image.fromarray(frame_rgb)
            frames.append(pil_image)
    
    cap.release()
    
    if not frames:
        raise ValueError("No frames successfully extracted")
    
    return frames
