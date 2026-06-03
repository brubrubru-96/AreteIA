import sys
import time

sys.path.append("/app")

import llm

print("Testing generate_completion from llm.py...")
start = time.time()
try:
    content, usage = llm.generate_completion(
        prompt="Hola, responde con 'OK' si recibes esto.",
        system_prompt="Eres un asistente útil."
    )
    duration = time.time() - start
    print(f"Success in {duration:.2f}s!")
    print(f"Content: {content}")
    print(f"Usage: {usage}")
except Exception as e:
    duration = time.time() - start
    print(f"Failed after {duration:.2f}s: {e}")
