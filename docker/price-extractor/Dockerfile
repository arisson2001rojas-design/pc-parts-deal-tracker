FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /service

COPY requirements.txt ./
RUN pip install --no-cache-dir --requirement requirements.txt \
    && useradd --create-home --uid 10001 extractor

COPY app.py extractor.py test_extractor.py ./

USER extractor

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --retries=5 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:8000/health', timeout=3)"

CMD ["gunicorn", "--bind", "0.0.0.0:8000", "--workers", "1", "--threads", "4", "--timeout", "45", "app:app"]
