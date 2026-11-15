
from fastapi import FastAPI, HTTPException
from sentence_transformers import SentenceTransformer

app = FastAPI()

# Downloaded once and cached locally
model = SentenceTransformer("sentence-transformers/all-MiniLM-L6-v2")

@app.get("/")
def home():
    return {"status": "Embedding service running"}

@app.post("/v1/embeddings")
def embed(data: dict):
    # OpenAI-compatible: {"model": "...", "input": "..." | [...]}.
    # "texts" is still accepted for older clients.
    texts = data.get("input", data.get("texts", []))

    if isinstance(texts, str):
        texts = [texts]

    if not texts:
        raise HTTPException(
            status_code=400,
            detail={"message": "No input provided"},
        )

    embeddings = model.encode(
        texts,
        normalize_embeddings=True
    )

    return {
        "object": "list",
        "model": data.get("model") or "all-MiniLM-L6-v2",
        "data": [
            {"object": "embedding", "index": i, "embedding": vector}
            for i, vector in enumerate(embeddings.tolist())
        ],
    }
