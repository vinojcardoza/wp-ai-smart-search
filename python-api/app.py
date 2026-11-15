
from fastapi import FastAPI
from sentence_transformers import SentenceTransformer

app = FastAPI()

# Downloaded once and cached locally
model = SentenceTransformer("sentence-transformers/all-MiniLM-L6-v2")

@app.get("/")
def home():
    return {"status": "Embedding service running"}

@app.post("/embed")
def embed(data: dict):

    texts = data.get("texts", [])
    
    if not texts:
        return {"error": "No text provided"}

    embeddings = model.encode(
        texts,
        normalize_embeddings=True
    )

    return {
        "dimensions": 384,
        "embeddings": embeddings.tolist()
    }
