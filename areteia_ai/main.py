from fastapi import FastAPI, BackgroundTasks, Request, UploadFile
from pydantic import BaseModel
import shutil
import logging
import json
import os

from rag.store import get_index_path, get_metadata_path
from schemas import (
    GenerateRequest, 
    SuggestionsResponse, 
    InstrumentDesign, 
    InstrumentItem,
    RubricDesign,
    CorrectionDesign,
    FeedbackClassification
)
from rag.utils import get_instrument_list
import json
import re


app = FastAPI(title="AreteIA AI Service")
logging.basicConfig(level=logging.INFO)

@app.on_event("startup")
async def startup_event():
    # Warm up the embedding model
    from rag.utils import get_model
    logging.info("Warming up embedding model (FastEmbed)...")
    get_model()
    
    # Warm up the guidelines index (course 0)
    from rag.store import load_index
    try:
        logging.info("Warming up Guidelines index (course 0)...")
        load_index(0)
        logging.info("Guidelines ready.")
    except Exception as e:
        logging.warning(f"Guidelines index not found at startup: {e}")
    
    logging.info("Service fully ready.")

class SyncRequest(BaseModel):
    course: dict = {}
    files: list = []

# Thread-safe (mostly) dictionary to track background task progress
INGESTION_PROGRESS = {}

class IngestRequest(BaseModel):
    course_id: int
    selected_files: list[str] = []

class SearchRequest(BaseModel):
    course_id: int
    query: str

# GenerateRequest moved to schemas.py

@app.get("/")
async def root():
    return {"message": "AreteIA AI Service is running"}


@app.post("/sync")
async def sync_course(request: SyncRequest):
    verified = []
    for f in request.files:
        path = f.get("localpath")
        if path and os.path.exists(path):
            verified.append(f.get("filename"))
    return {"status": "success", "files_verified": len(verified)}


@app.post("/ingest")
async def ingest_course(request: Request, background_tasks: BackgroundTasks):
    """
    Trigger ingestion for a course folder in the background.
    Accepts two modes:
      1. JSON body with 'base_sync_dir': fast path for shared-filesystem deploys
         (PHP and Python on the same machine). Files are copied locally, no HTTP upload.
      2. multipart/form-data with file bytes: fallback for Docker / remote deploys.
    """
    content_type = request.headers.get("content-type", "")

    # ------------------------------------------------------------------
    # Fast path: shared filesystem (bare-metal / same-machine deploy)
    # ------------------------------------------------------------------
    if "application/json" in content_type:
        body = await request.json()
        course_id = body.get("course_id")
        base_sync_dir = body.get("base_sync_dir", "")
        selected_files = body.get("selected_files", [])

        if not course_id:
            return {"status": "error", "message": "course_id is required"}

        # Security: normalize path to block traversal attempts
        safe_dir = os.path.realpath(base_sync_dir) if base_sync_dir else ""
        if not safe_dir or not os.path.isdir(safe_dir):
            return {"status": "path_unavailable", "message": f"Directory not accessible: {base_sync_dir!r}"}

        from rag.store import get_course_dir
        course_dir = os.path.realpath(get_course_dir(course_id))

        if safe_dir != course_dir:
            # Different paths on the same machine: local copy (fast, no network overhead)
            if os.path.exists(course_dir):
                shutil.rmtree(course_dir)
            shutil.copytree(safe_dir, course_dir)
        # else: base_sync_dir IS already the course_dir — nothing to copy.

        INGESTION_PROGRESS[course_id] = {
            "progress": 0,
            "message": "Iniciando (path-based)...",
            "selected_files": selected_files,
        }

        def progress_callback_path(val, msg):
            INGESTION_PROGRESS[course_id] = {
                "progress": val,
                "message": msg,
                "selected_files": selected_files,
            }

        from rag.pipeline import run_ingestion
        background_tasks.add_task(run_ingestion, course_id, progress_callback=progress_callback_path)
        return {
            "status": "started",
            "message": f"Ingestion triggered (path-based) for course {course_id}",
        }

    # ------------------------------------------------------------------
    # Fallback: multipart upload (Docker / remote Python service)
    # ------------------------------------------------------------------
    form_data = await request.form()
    course_id_str = form_data.get("course_id")
    
    if not course_id_str:
        return {"status": "error", "message": "course_id is required"}
        
    course_id = int(course_id_str)

    from rag.pipeline import run_ingestion
    from rag.store import get_course_dir
    
    course_dir = get_course_dir(course_id)
    if os.path.exists(course_dir):
        shutil.rmtree(course_dir)
    os.makedirs(course_dir, exist_ok=True)
    
    selected_files = []
    for key, value in form_data.multi_items():
        # Check if it's a file by looking for the filename attribute (duck typing)
        filename = getattr(value, 'filename', None)
        if filename:
            rel_path = filename.lstrip('/')
            selected_files.append(rel_path)
            
            dest_path = os.path.join(course_dir, rel_path)
            os.makedirs(os.path.dirname(dest_path), exist_ok=True)
            
            with open(dest_path, "wb") as buffer:
                shutil.copyfileobj(value.file, buffer)
            logging.info(f"Saved uploaded file: {rel_path} to {dest_path}")
    
    if not selected_files:
        logging.warning(f"No files were received in the multipart request for course {course_id}")
    else:
        logging.info(f"Received and saved {len(selected_files)} files for course {course_id}")
    
    # Initialize progress
    INGESTION_PROGRESS[course_id] = {
        "progress": 0, 
        "message": "Iniciando...",
        "selected_files": selected_files
    }
    
    def progress_callback(val, msg):
        INGESTION_PROGRESS[course_id] = {
            "progress": val, 
            "message": msg,
            "selected_files": selected_files
        }

    background_tasks.add_task(run_ingestion, course_id, progress_callback=progress_callback)
    return {
        "status": "started",
        "message": f"Ingestion triggered in background for course {course_id}"
    }

@app.post("/search")
async def search_endpoint(request: SearchRequest):
    """
    Query a course embedding for RAG.
    """
    if not request.course_id or not request.query:
        return {"status": "error", "message": "course_id and query required"}

    from rag.search import search_course
    try:
        results = search_course(request.course_id, request.query)
        return {"status": "success", "results": results}
    except Exception as e:
        logging.exception("Error searching course")
        return {"status": "error", "message": str(e)}

@app.get("/instruments")
async def list_instruments():
    """
    Returns the full list of instruments from the master document.
    """
    try:
        instruments = get_instrument_list()
        return {"status": "success", "instruments": instruments}
    except Exception as e:
        return {"status": "error", "message": str(e)}


@app.post("/preview")
async def preview_endpoint(request: GenerateRequest):
    """
    Returns the prompts that would be sent to the LLM.
    """
    try:
        prompt, system_prompt, _ = await _prepare_prompt_data(request)
        if not prompt:
            return {"status": "error", "message": f"Step {request.step} not supported for preview"}
        
        if isinstance(prompt, dict) and prompt.get("status") == "error":
            return prompt

        return {
            "status": "success",
            "system_prompt": system_prompt,
            "user_prompt": prompt
        }
    except Exception as e:
        logging.exception("Error in /preview")
        return {"status": "error", "message": str(e)}


async def _prepare_prompt_data(request: GenerateRequest):
    """
    Internal helper to search RAG/Guidelines and select the correct prompt templates.
    """
    def _build_structured_materials(course_id, obj_list, all_vectors):
        from rag.search import search_course_by_vector
        materials_parts = []
        seen_texts = set()
        vector_idx = 1
        for i, obj in enumerate(obj_list):
            obj_text = obj.get('text', '')
            bloom = obj.get('bloom', 'GENERAL').upper()
            fragments = []
            if obj_text and vector_idx < len(all_vectors):
                results = search_course_by_vector(course_id, all_vectors[vector_idx], top_k=2)
                vector_idx += 1
                for res in results:
                    txt = " ".join(res['text'].split())
                    if txt and txt not in seen_texts:
                        fragments.append(f"            - Extracto: \"{txt}\"\n            - Referencia: {res.get('filename', 'Archivo desconocido')}")
                        seen_texts.add(txt)
            obj_header = f"- Objetivo {i+1}:\n"
            obj_header += f"        - Nivel taxonómico: {bloom}\n"
            obj_header += f"        - Declaración objetivo: {obj_text}\n"
            obj_header += f"        - Extracto de los materiales:\n"
            if fragments:
                materials_parts.append(obj_header + "\n".join(fragments))
            else:
                materials_parts.append(obj_header + "            (No se encontraron materiales específicos en los recursos seleccionados)")
        return "\n\n".join(materials_parts) if materials_parts else "No se proporcionaron objetivos específicos."

    try:
        from llm import (
            classify_feedback,
            get_suggestions_prompt, 
            get_design_prompt, 
            get_rubric_prompt,
            get_correction_prompt
        )
        from rag.search import search_course, search_guidelines

        if request.feedback and request.feedback.strip() and request.feedback.strip().lower() not in ('undefined', 'null'):
            raw_feedback = classify_feedback(request.feedback.strip())
            if raw_feedback:
                try:
                    # Clean JSON in case LLM added markdown wrappers
                    json_fb = re.sub(r'^```json|```$', '', raw_feedback, flags=re.MULTILINE).strip()
                    fb_data = FeedbackClassification.parse_raw(json_fb)
                    if not fb_data.is_valid:
                        return {
                            "status": "error", 
                            "message": "Solicitud de ajuste no válida",
                            "reason": fb_data.reason or "El comentario no parece ser una instrucción pedagógica válida."
                        }, None, None
                except Exception as e:
                    logging.warning(f"Failed to parse feedback classification: {e}")
            else:
                logging.warning("Classification failed (LLM returned None)")

        # 1. Collect all queries that need embedding for batch processing
        queries = []
        # Main query for guidelines and fallback RAG
        main_query = request.objective or "evaluación educativa"
        queries.append(main_query)
        
        # Specific objective queries
        obj_list = []
        if request.objective_json:
            try:
                obj_list = json.loads(request.objective_json)
                for obj in obj_list:
                    txt = obj.get('text', '')
                    if txt:
                        queries.append(txt)
            except:
                pass

        # 2. Embed ALL queries in a single batch
        from rag.utils import embed_text_chunks
        all_vectors = embed_text_chunks(queries, prefix="query: ")
        
        # 3. Perform searches using pre-computed vectors
        from rag.search import search_course_by_vector
        
        # Guidelines (only if NOT step 4)
        guidelines_text = ""
        if request.step != 4:
            g_results = search_course_by_vector(0, all_vectors[0], top_k=3)
            guidelines_text = "DIRECTRICES PEDAGÓGICAS (REGLAS GLOBALES):\n" + "\n".join([f"- {res['text']}" for res in g_results[:3]]) + "\n\n"

        # Unified Structured RAG context calculation
        materials_str = _build_structured_materials(request.course_id, obj_list, all_vectors)
        full_context = f"{guidelines_text}CONTEXTO EXTRAÍDO DE MATERIALES DEL CURSO:\n{materials_str}"

        # 3. Build Dimensions String (D1, D3, D4)
        # Use granular fields if available, otherwise fallback to the concatenated string
        dim_parts = []
        if request.d1_content: dim_parts.append(f"Contenido: {request.d1_content}")
        if request.d3_function: dim_parts.append(f"Función: {request.d3_function}")
        if request.d4_modality: dim_parts.append(f"Modalidad: {request.d4_modality}")
        
        dimensions = "\n".join(dim_parts) if dim_parts else request.dimensions

        # 4. Build prompt based on step
        prompt = ""
        course_name = request.course_title or "el curso"
        system_prompt = f"Eres un experto en pedagogía y diseño de instrumentos de evaluación y de apoyo a la corrección en el curso {course_name}."
        schema = None
        
        if request.step == 4:
            # Include the master instrument list in the context for Step 4
            master_instruments = get_instrument_list()
            instr_list_str = "\n".join([f"- {instr['name']}: {instr['definition'][:200]}..." for instr in master_instruments])
            extended_context = f"{full_context}\n\nLISTA DE INSTRUMENTOS DISPONIBLES (ELIGE SOLO DE AQUÍ):\n{instr_list_str}"
            
            prompt = get_suggestions_prompt(
                request.summary, request.objective, dimensions, extended_context, request.feedback,
                d1_content=request.d1_content,
                d3_function=request.d3_function,
                d4_modality=request.d4_modality,
            )
            schema = SuggestionsResponse
        elif request.step == 5:
            # 1. Load Instrument Document & Resolve ID
            instrument_desc = ""
            chosen_id = None
            try:
                inst_list = get_instrument_list()
                target_raw = (request.chosen_instrument or "").strip().lower()
                
                for inst in inst_list:
                    # Check ID match first (future-proof) or Name match (legacy support)
                    if inst.get('id') == request.chosen_instrument or inst.get('name', '').strip().lower() == target_raw:
                        instrument_desc = inst.get('definition', '')
                        chosen_id = inst.get('id')
                        break
                
                if not chosen_id:
                    logging.warning(f"Instrument '{request.chosen_instrument}' not found in master catalog.")
            except Exception as e:
                logging.error(f"Error loading instrument context: {e}")

            # 4. Load & Filter Allowed Question Types
            valid_types = []
            try:
                qt_path = os.path.join(os.path.dirname(__file__), "rag/documentos_maestros/tipos_de_preguntas.json")
                if os.path.exists(qt_path):
                    with open(qt_path, "r", encoding="utf-8") as f:
                        valid_types = json.load(f)
                
                encaje_path = os.path.join(os.path.dirname(__file__), "rag/documentos_maestros/encaje_instrumentos_items.json")
                if os.path.exists(encaje_path):
                    with open(encaje_path, "r", encoding="utf-8") as f:
                        encaje_map = json.load(f)
                    
                    # If we have a valid instrument ID, filter types by their IDs
                    if chosen_id and chosen_id in encaje_map:
                        allowed_type_ids = encaje_map[chosen_id]
                        valid_types = [
                            t for t in valid_types 
                            if t.get('id') in allowed_type_ids
                        ]
                    else:
                        logging.warning(f"No specific question types mapped for instrument ID: '{chosen_id}'")
            except Exception as e:
                logging.error(f"Error handling question types: {e}")

            prompt = get_design_prompt(
                chosen_instrument=request.chosen_instrument,
                instrument_desc=instrument_desc,
                structured_materials=materials_str,
                num_items=request.num_items,
                valid_types=valid_types,
                feedback=request.feedback,
                current_design=request.instrument_content,
                d1_content=request.d1_content,
                d3_function=request.d3_function,
                d4_modality=request.d4_modality,
                instrument_id=chosen_id or "",
            )
            schema = InstrumentDesign
        elif request.step == 5.1:
            # Single-item adjustment: resolve instrument types, then generate one adjusted item
            chosen_id = None
            valid_types = []
            try:
                inst_list = get_instrument_list()
                target_raw = (request.chosen_instrument or "").strip().lower()
                for inst in inst_list:
                    if inst.get('id') == request.chosen_instrument or inst.get('name', '').strip().lower() == target_raw:
                        chosen_id = inst.get('id')
                        break

                qt_path = os.path.join(os.path.dirname(__file__), "rag/documentos_maestros/tipos_de_preguntas.json")
                if os.path.exists(qt_path):
                    with open(qt_path, "r", encoding="utf-8") as f:
                        valid_types = json.load(f)

                encaje_path = os.path.join(os.path.dirname(__file__), "rag/documentos_maestros/encaje_instrumentos_items.json")
                if os.path.exists(encaje_path):
                    with open(encaje_path, "r", encoding="utf-8") as f:
                        encaje_map = json.load(f)
                    if chosen_id and chosen_id in encaje_map:
                        allowed_type_ids = encaje_map[chosen_id]
                        valid_types = [t for t in valid_types if t.get('id') in allowed_type_ids]
            except Exception as e:
                logging.error(f"Error loading question types for step 5.1: {e}")

            from llm import get_adjust_item_prompt
            prompt = get_adjust_item_prompt(
                item=request.item or {},
                instruction=request.feedback,
                valid_types=valid_types,
                objective_json=request.objective_json,
            )
            schema = InstrumentItem
        elif request.step == 6:
            prompt = get_rubric_prompt(request.instrument_content, request.objective, full_context, request.feedback)
            schema = RubricDesign
        elif request.step == 9:
            prompt = get_correction_prompt(
                correction_type=request.correction_type,
                correction_label=request.correction_label,
                chosen_instrument=request.chosen_instrument,
                instrument_content=request.instrument_content,
                quiz_items_json=request.quiz_items_json,
                objective=request.objective,
                full_context=full_context,
                feedback=request.feedback
            )
            schema = CorrectionDesign
        else:
            return None, None, None

        return prompt, system_prompt, schema
    except Exception as e:
        logging.exception("Error preparing prompt data")
        return {"status": "error", "message": str(e)}, None, None

@app.post("/generate")
async def generate_endpoint(request: GenerateRequest):
    """
    Main generative endpoint for Steps 4, 5, and 6.
    """
    try:
        # from llm import generate_completion
        from llm import _llm_instance
        # Singleton instance initialized on module load
        
        prompt, system_prompt, schema = await _prepare_prompt_data(request)
        
        if not prompt:
            return {"status": "error", "message": f"Step {request.step} not supported for generation"}
        
        if isinstance(prompt, dict) and prompt.get("status") == "error":
            return prompt

        # 4. Call LLM with automatic retry on parse failure (max 3 attempts)
        MAX_ATTEMPTS = 3
        last_error = None
        usage = None

        for attempt in range(1, MAX_ATTEMPTS + 1):
            response_text, usage = _llm_instance.generate_completion(prompt, system_prompt)

            if not response_text:
                last_error = "AI generation failed"
                logging.warning("LLM returned empty response on attempt %d/%d", attempt, MAX_ATTEMPTS)
                continue

            # 5. Parse and Validate JSON
            try:
                # Step 1: strip markdown code fences (```json ... ``` or ``` ... ```)
                clean = re.sub(r'^```(?:json)?\s*', '', response_text, flags=re.MULTILINE)
                clean = re.sub(r'\s*```\s*$', '', clean, flags=re.MULTILINE).strip()
                # Step 2: extract the outermost { ... } block in case the LLM added preamble/postamble
                start = clean.find('{')
                end = clean.rfind('}')
                if start != -1 and end != -1 and end > start:
                    clean = clean[start:end + 1]
                validated_data = schema.parse_raw(clean)
                return {
                    "status": "success",
                    "output": validated_data.dict(),
                    "usage": usage
                }
            except Exception as e:
                last_error = str(e)
                logging.warning(
                    "Validation failed on attempt %d/%d. Raw response: %s",
                    attempt, MAX_ATTEMPTS, response_text[:300]
                )
                # Continue to next attempt; avoid retrying if last attempt
                if attempt < MAX_ATTEMPTS:
                    continue

        # All attempts exhausted
        logging.error("All %d LLM attempts failed. Last error: %s", MAX_ATTEMPTS, last_error)
        return {
            "status": "error",
            "message": "La IA generó un formato no válido. Por favor, intenta de nuevo o ajusta tu petición.",
            "details": last_error
        }

    except Exception as e:
        logging.exception("Error in /generate")
        return {"status": "error", "message": str(e)}
    

@app.get("/status/{course_id}")
def check_status(course_id: int):
    try:
        from rag.store import get_index_path, get_metadata_path, get_course_dir
        import pickle

        index_path = get_index_path(course_id)
        meta_path  = get_metadata_path(course_id)
        sel_path   = f"{get_course_dir(course_id)}/selected_files.json"

        exists = os.path.exists(index_path) and os.path.exists(meta_path)
        chunks = 0
        selected_files = []

        if exists:
            with open(meta_path, "rb") as f:
                meta = pickle.load(f)
            chunks = len(meta)

        if os.path.exists(sel_path):
            with open(sel_path, "r", encoding="utf-8") as f:
                selected_files = json.load(f)

        # 1. Check active background progress first
        if course_id in INGESTION_PROGRESS:
            prog = INGESTION_PROGRESS[course_id]
            # If finished (100%), remove from tracker so next call returns the static state
            if prog.get("progress", 0) >= 100:
                INGESTION_PROGRESS.pop(course_id, None)
            else:
                return {
                    "status": "success", 
                    "data": prog,
                    "selected_files": prog.get("selected_files", [])
                }

        return {
            "status": "success",
            "embedding_exists": exists,
            "chunks": chunks,
            "selected_files": selected_files,
            "data": {
                "progress": 100 if exists else 0,
                "message": "Completado" if exists else "Pendiente",
                "embedding_exists": exists,
                "chunks": chunks
            }
        }
    except Exception as e:
        return {"status": "error", "message": str(e)}


@app.delete("/ingest/{course_id}")
async def delete_embeddings(course_id: int):
    """
    Delete the RAG index, metadata and selected_files list for a course.
    """
    from rag.store import get_index_path, get_metadata_path, get_course_dir
    import os
    for path in [
        get_index_path(course_id),
        get_metadata_path(course_id),
        f"{get_course_dir(course_id)}/selected_files.json",
    ]:
        if os.path.exists(path):
            os.remove(path)
    INGESTION_PROGRESS.pop(course_id, None)
    return {"status": "success", "message": "Embeddings deleted"}
