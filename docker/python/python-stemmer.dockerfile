FROM python:3.9-slim AS builder

WORKDIR /app

ENV PYTHONDONTWRITEBYTECODE=1
ENV PYTHONUNBUFFERED=1

# Install dependencies in a single layer and clean up
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
# Install dependencies and clean up pip cache
RUN pip install --no-cache-dir -r requirements.txt \
    && pip cache purge

# Create a leaner production image
FROM python:3.9-slim

WORKDIR /app

ENV PYTHONDONTWRITEBYTECODE=1
ENV PYTHONUNBUFFERED=1
ENV HF_HOME=/huggingface_cache
ENV TRANSFORMERS_CACHE=/huggingface_cache
ENV HF_DATASETS_CACHE=/huggingface_cache

# Copy over the installed packages and minimal dependencies
COPY --from=builder /usr/local/lib/python3.9/site-packages /usr/local/lib/python3.9/site-packages
COPY --from=builder /usr/local/bin /usr/local/bin

# Only install the minimal runtime dependencies needed
RUN apt-get update && apt-get install -y --no-install-recommends \
    libgomp1 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy only the necessary application files
COPY stemmer_service.py ./

EXPOSE 5050

CMD ["python", "stemmer_service.py"]
