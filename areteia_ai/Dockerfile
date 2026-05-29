FROM python:3.11.9-slim

# ENV http_proxy=http://proxy.fi.uba.ar:8080 \
#     https_proxy=http://proxy.fi.uba.ar:8080 \
#     no_proxy=localhost,127.0.0.1

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

EXPOSE 8000

CMD ["python", "-m", "uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]