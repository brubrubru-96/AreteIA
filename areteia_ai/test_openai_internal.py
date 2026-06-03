import time
from sentence_transformers import SentenceTransformer

print("Testing loading model with local_files_only=True...")
start = time.time()
try:
    model = SentenceTransformer("intfloat/multilingual-e5-small", local_files_only=True)
    duration = time.time() - start
    print(f"Success loading with local_files_only=True in {duration:.2f}s!")
except Exception as e:
    duration = time.time() - start
    print(f"Failed loading with local_files_only=True after {duration:.2f}s: {e}")
    
    print("Trying normal load...")
    start2 = time.time()
    try:
        model = SentenceTransformer("intfloat/multilingual-e5-small")
        duration2 = time.time() - start2
        print(f"Success normal loading in {duration2:.2f}s!")
    except Exception as e2:
        print(f"Failed normal loading: {e2}")
