import os
import logging
from abc import ABC, abstractmethod
from dotenv import load_dotenv

load_dotenv()

class LLMProvider(ABC):
    @abstractmethod
    def generate_completion(self, prompt: str, system_prompt: str) -> tuple[str, dict]:
        pass

class DashScopeProvider(LLMProvider):
    def __init__(self):
        import dashscope
        from dashscope import Generation
        self.Generation = Generation
        
        dashscope.api_key = os.getenv("DASHSCOPE_API_KEY")
        self.model = os.getenv("DASHSCOPE_MODEL", "qwen-plus")
        
        # Fix for workspace-specific endpoints
        base_url = os.getenv("DASHSCOPE_BASE_URL")
        if base_url:
            dashscope.base_http_api_url = base_url

    def generate_completion(self, prompt: str, system_prompt: str) -> tuple[str, dict]:
        try:
            messages = [
                {'role': 'system', 'content': system_prompt},
                {'role': 'user', 'content': prompt}
            ]
            response = self.Generation.call(
                model=self.model,
                messages=messages,
                result_format='message',
            )
            if response.status_code == 200:
                content = response.output.choices[0].message.content
                usage = {
                    "input_tokens": response.usage.input_tokens,
                    "output_tokens": response.usage.output_tokens,
                    "total_tokens": response.usage.total_tokens
                }
                return content, usage
            else:
                logging.error(f"DashScope Error: {response.code} - {response.message}")
                return None, None
        except Exception as e:
            logging.exception("Exception during DashScope call")
            return None, None

class OpenAIProvider(LLMProvider):
    def __init__(self):
        import openai
        # Avoid openai SDK crash when base URL is present but empty in the environment.
        base_url = os.getenv("OPENAI_BASE_URL")
        if base_url is not None and not base_url.strip():
            os.environ.pop("OPENAI_BASE_URL", None)
            
        self.client = openai.OpenAI(api_key=os.getenv("OPENAI_API_KEY"))
        self.model = os.getenv("OPENAI_MODEL", "gpt-4o")

    def generate_completion(self, prompt: str, system_prompt: str) -> tuple[str, dict]:
        try:
            response = self.client.chat.completions.create(
                model=self.model,
                messages=[
                    {"role": "system", "content": system_prompt},
                    {"role": "user", "content": prompt}
                ]
            )
            content = response.choices[0].message.content
            usage = {
                "input_tokens": response.usage.prompt_tokens,
                "output_tokens": response.usage.completion_tokens,
                "total_tokens": response.usage.total_tokens
            }
            return content, usage
        except Exception as e:
            logging.exception("Exception during OpenAI call")
            return None, None

def get_llm_provider() -> LLMProvider:
    provider_name = os.getenv("LLM_PROVIDER", "dashscope").lower()
    if provider_name == "openai":
        return OpenAIProvider()
    # Default to DashScope
    return DashScopeProvider()

# Singleton instance initialized on module load
_llm_instance = get_llm_provider()

def generate_completion(prompt: str, system_prompt: str = "Eres un experto en pedagogía y diseño de instrumentos de evaluación."):
    """
    Calls the configured LLM API (via LLM_PROVIDER env var) for text generation.
    Returns (content, usage)
    """
    return _llm_instance.generate_completion(prompt, system_prompt)

def classify_feedback(feedback_text: str) -> str:
    """
    Classifies if user feedback is a valid pedagogical adjustment request.
    Returns a JSON string matching FeedbackClassification schema.
    """
    system_prompt = """Eres un evaluador de entradas de usuario para una IA pedagógica.
  Tu tarea es determinar si el texto del usuario es una solicitud válida de AJUSTE o CORRECCIÓN sobre un material de evaluación (ej: 'hazlo más difícil', 'usa otro caso', 'cambia el tono').
  Si el usuario pide algo fuera de contexto (chistes, insultos, temas no educativos), marca is_valid como false.
  Responde UNICAMENTE con un JSON: {"is_valid": bool, "reason": "breve explicación si es falso"}"""
    
    res, _ = generate_completion(feedback_text, system_prompt)
    return res

# ---------------------------------------------------------------------------
# Instrument-specific design guidance (from pedagogical advisors — CITEP/UBA)
# Keys match the IDs in rag/documentos_maestros/instrumentos.json
# ---------------------------------------------------------------------------
INSTRUMENT_SPECIFIC_TEMPLATES = {
    "analisis-fuentes": """\
**ESTRUCTURA OBLIGATORIA — ANÁLISIS DE FUENTES DOCUMENTALES:**
El instrumento debe construirse con estos 5 componentes como ítems separados:
1. **Corpus documental** — Presenta las fuentes seleccionadas (textuales, audiovisuales, gráficas o legislativas) con justificación pedagógica de por qué ese conjunto es coherente con los objetivos y el tipo de contenido definidos.
2. **Situación-problema o pregunta orientadora** — El problema que da sentido al análisis y que el estudiante debe responder usando las fuentes como evidencia.
3. **Consignas operativas** — Instrucciones específicas que activen la operación cognitiva esperada (comparar, contextualizar, argumentar, detectar sesgos, sintetizar, tomar posición, según lo definido en los objetivos).
4. **Criterios de heurística documental** — Los parámetros con que el estudiante debe evaluar las fuentes antes de usarlas: autoría, fecha, intencionalidad, contexto de producción, confiabilidad.
5. **Tarea de producción** — Formato de respuesta esperado con indicaciones claras sobre extensión y estructura.""",

    "aps": """\
**ESTRUCTURA OBLIGATORIA — APRENDIZAJE SERVICIO (APS):**
El instrumento debe incluir estos componentes como ítems separados:
1. **Identificación de la necesidad** — Cómo diagnosticar una problemática real y relevante en una comunidad específica.
2. **Articulación curricular** — Evidencia clara de cómo los contenidos teóricos de la asignatura se aplican para resolver o mitigar la necesidad identificada.
3. **Propuesta de intervención** — Fundamentación, objetivos, etapas de desarrollo de las distintas intervenciones y modalidad de evaluación.
4. **Reflexión crítica** — Propuesta de reflexión (antes, durante y después) sobre el impacto social de la práctica y el propio proceso de aprendizaje.""",

    "ensayo-desarrollo": """\
**ESTRUCTURA OBLIGATORIA — ENSAYO/DESARROLLO:**
Construí la consigna con estos 5 componentes como ítems separados:
1. **Situación de escritura** — Una pregunta, problema o dilema que abra una tensión genuina dentro del campo disciplinar. Debe obligar a tomar posición y argumentarla, NO a exponer información.
2. **Condiciones de producción** — Especificá si es domiciliario o presencial, individual o colaborativo, con o sin fuentes provistas, extensión esperada y si se contempla alguna instancia de borrador previo.
3. **Criterios de textualidad** — Qué se espera en términos de coherencia, cohesión, estructura argumentativa, uso de conectores lógicos y progresión temática.
4. **Criterios de contenido disciplinar** — Precisión conceptual, pertinencia de los argumentos respecto de la bibliografía de la materia y capacidad de contextualización dentro del campo.
5. **Criterios de posicionamiento argumentativo** — Qué se espera en términos de sostener una tesis propia, anticipar objeciones y responderlas.""",

    "debate": """\
**ESTRUCTURA OBLIGATORIA — DEBATE:**
El instrumento debe incluir estos componentes como ítems separados:
1. **Tema y problematización** — Define un tema controversial vinculado a los contenidos, presentando al menos dos perspectivas legítimas en tensión.
2. **Dinámica de roles** — Establece una estructura de roles (posturas a favor, en contra, observadores críticos) y cómo se asignarán.
3. **Protocolo de participación** — Define las reglas de interacción, tiempos de intervención y el rol del moderador.
4. **Configuración de la modalidad** — Diseña el flujo de la actividad (sincrónica/asincrónica), detallando el paso a paso.
5. **Criterios de evaluación** — Los criterios mínimos que deben cumplir las intervenciones (uso de bibliografía, contraargumentación, escucha activa).""",

    "escape-room": """\
**ESTRUCTURA OBLIGATORIA — ESCAPE ROOM:**
El instrumento debe incluir estos componentes como ítems separados:
1. **Narrativa o mundo ficcional** — Un escenario que dé marco y sentido a toda la experiencia, coherente con el campo disciplinar, que genere inmersión y urgencia sin ser arbitrario respecto de los contenidos.
2. **Desafío central o misión** — El problema global que los estudiantes deben resolver para "escapar", directamente coherente con los objetivos de aprendizaje.
3. **Pruebas o puzzles (4-6)** — Una secuencia de desafíos donde cada uno active una operación cognitiva distinta (analizar, relacionar, aplicar, deducir, transferir) anclada en los contenidos.
4. **Sistema de pistas y ayuda** — Cuántas pistas están disponibles, cómo se solicitan y qué nivel de orientación ofrecen.
5. **Tiempo y dinámica grupal** — Límite temporal justificado pedagógicamente y especificación de roles del equipo.
6. **Desenlace y debriefing** — Instancia de reflexión post-juego sobre qué conceptos pusieron en juego.""",

    "esquema": """\
**ESTRUCTURA OBLIGATORIA — ESQUEMA:**
El instrumento debe incluir:
1. **Consigna de trabajo** — Explicar al estudiante el objetivo de aprendizaje y el tipo de esquema a elaborar (llaves, flechas, ramas, columnas).
2. **Núcleo conceptual** — Los conceptos o ideas principales que son obligatorios de representar y cuáles son secundarios.
3. **Arquitectura visual** — Instrucciones sobre el formato gráfico específico para evidenciar la jerarquía (de lo general a lo particular) o la relación causal.
4. **Restricción de densidad** — Reglas de "economía de palabras" (titulares o frases breves que demuestren síntesis).
5. **Lista de verificación (checklist)** — Criterios técnicos que el estudiante puede usar antes de entregar para asegurar que el esquema cumple con jerarquía y síntesis.""",

    "estudio-caso": """\
**ESTRUCTURA OBLIGATORIA — ESTUDIO DE CASO (modelo Wassermann):**
│ CAMPO "scenario": CONTIENE la narrativa completa del caso (historia realista y detallada de
│   3-5 párrafos basada en el campo profesional, con datos suficientes y algunos irrelevantes
│   para que el estudiante discrimine). NUNCA pongas la narrativa en los ítems.
│ ITEMS: Cada uno es una TAREA o PREGUNTA sobre el caso narrado en el scenario.
Componentes obligatorios como ítems (tipo “Ensayo / Respuesta abierta”):
1. **Preguntas críticas de análisis** — Preguntas que obliguen a ANALIZAR el caso, no a recordar (niveles Bloom ANALIZAR/EVALUAR/CREAR).
2. **Dinámica de trabajo** — Instrucciones claras para trabajo en pequeños grupos y plenario.
3. **Criterios de evaluación** — Qué se evaluará: delimitar el problema, identificar datos relevantes, proponer alternativas fundamentadas.""",

    "ev-oral": """\
**ESTRUCTURA OBLIGATORIA — EVALUACIÓN ORAL:**
El instrumento debe incluir:
1. **Guía de preparación para el estudiante** — Qué se espera de él y cómo debe prepararse.
2. **Preguntas base** — Preguntas de amplitud media que inviten a argumentar, relacionar y fundamentar (no a memorizar).
3. **Protocolo de repreguntas** — Indicaciones para que el docente profundice en las respuestas.
4. **Criterios de evaluación** — Claridad expositiva, argumentación y fundamentación, precisión conceptual, pensamiento crítico, gestión del tiempo.""",

    "glosario": """\
**ESTRUCTURA OBLIGATORIA — GLOSARIO:**
El instrumento debe incluir:
1. **Consigna de trabajo** — Explicar el valor del glosario como herramienta de consulta profesional.
2. **Criterio de selección de términos** — Cómo elegir los términos "críticos" de la asignatura.
3. **Arquitectura de la definición** — Cada definición debe ser contextualizada en el ámbito de la disciplina (no definiciones de diccionario general).
4. **Mapeo de relaciones** — Cada término debe vincularse con al menos otro concepto del glosario.
5. **Guía de estilo y ejemplo modelo** — Extensión máxima por término, tono de escritura, formato de los ejemplos y una entrada modelo de referencia.""",

    "investigacion": """\
**ESTRUCTURA OBLIGATORIA — PROYECTO DE INVESTIGACIÓN:**
El instrumento debe incluir estos componentes como ítems separados:
1. **Problema de investigación** — Orienta a formular una pregunta genuinamente investigable que muestre dominio del estado del arte.
2. **Estado del arte o marco referencial** — Revisión crítica de la literatura que muestre dónde está el vacío o la tensión que justifica la investigación.
3. **Objetivos** — Distinción clara entre objetivo general y objetivos específicos.
4. **Marco teórico o conceptual** — Uso de conceptos y perspectivas teóricas como herramientas para interpretar los datos (no como glosario).
5. **Estrategia metodológica** — Justificación del enfoque (cuantitativo, cualitativo, mixto), diseño, técnicas de recolección y análisis.
6. **Corpus o fuentes de datos** — Fundamentación de qué se va a analizar y por qué ese corpus es pertinente.
7. **Plan de trabajo** — Organización temporal realista de las etapas del proyecto.
8. **Consideraciones éticas** — Reflexión sobre resguardos éticos del trabajo con personas, datos sensibles o comunidades.
9. **Resultados esperados** — Anticipación fundamentada sobre el tipo de conocimiento que podría generar el proyecto.""",

    "rol": """\
**ESTRUCTURA OBLIGATORIA — JUEGO DE ROL:**
El instrumento debe incluir estos componentes como ítems separados:
1. **Escenario de simulación** — Una situación conflictiva o dilema profesional verosímil con múltiples posiciones legítimas en tensión.
2. **Fichas de personaje** — Perfiles detallados con: contexto (quién es y qué sabe), objetivo de rol (qué debe lograr/defender), limitaciones (qué no puede ceder).
3. **Protocolo de acción** — Reglas de la representación: fases, tiempos, cómo se llega a un cierre.
4. **Dispositivo de reflexión post-rol** — Preguntas para la instancia de reflexión grupal sobre la vinculación entre teoría y acción.
5. **Grilla de observación** — Criterios de evaluación: empatía y adaptación, argumentación situada, coherencia con el rol.""",

    "mapa-conceptual": """\
**ESTRUCTURA OBLIGATORIA — MAPA CONCEPTUAL:**
El instrumento debe incluir:
1. **Consigna detallada** — Explicar la diferencia entre mapa conceptual y cuadro sinóptico; qué se espera en términos de jerarquía (de lo más general a los ejemplos) y palabras de enlace.
2. **Listado de conceptos semilla** — Un set de 10-15 conceptos clave que el alumno debe incluir obligatoriamente (con espacio para agregar otros).
3. **Requisito de enlaces cruzados** — Al menos X relaciones entre conceptos de diferentes segmentos del mapa para demostrar visión integradora.
4. **Criterios de evaluación** — Pertinencia, jerarquía, relaciones, integración de conceptos.""",

    "monografia": """\
**ESTRUCTURA OBLIGATORIA — MONOGRAFÍA:**
El instrumento debe incluir estos componentes como ítems separados:
1. **Delimitación temática** — Orienta hacia un problema o pregunta dentro del tema (no hacia "un tema" en general).
2. **Consigna o problema de investigación** — La pregunta o problema que organiza el desarrollo.
3. **Condiciones de producción** — Extensión, formato de citación requerido, tipo de fuentes aceptadas, si incluye índice/resumen/introducción formal, plazos.
4. **Estructura formal esperada** — Qué se espera en cada sección: introducción, desarrollo, conclusiones, referencias.
5. **Criterios de manejo de fuentes** — Selección, citación, paráfrasis e integración de fuentes especializadas.
6. **Criterios de contenido disciplinar** — Precisión conceptual, pertinencia de las fuentes, capacidad de articular perspectivas teóricas.
7. **Criterios de textualidad académica** — Coherencia, cohesión, registro formal, progresión temática.""",

    "portafolio": """\
**ESTRUCTURA OBLIGATORIA — PORTAFOLIO:**
El instrumento debe incluir estos 5 pilares como ítems separados:
1. **Curaduría de producciones** — Pautas para que el estudiante seleccione sus trabajos más representativos justificando por qué cada pieza es evidencia de aprendizaje.
2. **Criterios de autoevaluación** — Cómo realizar un análisis crítico de cada producción identificando fortalezas y debilidades.
3. **Ciclo de mejora (feedback-iteración)** — Un apartado donde el estudiante muestre una versión original y su versión mejorada, explicando los cambios a partir de las devoluciones docentes.
4. **Hitos de preentrega** — Cronograma de entregas parciales orientadas al feedback formativo (no a la nota).
5. **Reflexión metacognitiva final** — Instancia de mirada retrospectiva sobre el propio proceso de aprendizaje.""",
}


def _build_dimension_rules_for_suggestions(d1_content="", d3_function="", d4_modality=""):
    """
    Builds explicit dimension-based filtering rules for Step 4 (instrument suggestions).
    Returns a formatted string to inject into the prompt.
    """
    rules = []
    d3 = (d3_function or "").strip().upper()
    d4 = (d4_modality or "").strip().upper()
    d1 = (d1_content or "").strip().upper()

    if "DIAGN" in d3:
        rules.append("- **FUNCIÓN DIAGNÓSTICA:** Privilegiá instrumentos que permitan explorar saberes previos sin generar ansiedad, que admitan la respuesta 'no sé', con tono no punitivo y motivador. Descartá instrumentos que califiquen o certifiquen.")
    elif "FORMATIV" in d3:
        rules.append("- **FUNCIÓN FORMATIVA:** Privilegiá instrumentos que muestren el PROCESO de razonamiento (no sólo el resultado), que permitan dar retroalimentación constructiva y que activen la metacognición del estudiante. Son ideales los que admiten instancias iterativas.")
    elif "SUMATIV" in d3:
        rules.append("- **FUNCIÓN SUMATIVA:** Privilegiá instrumentos que certifiquen el nivel de logro con criterios claros y formales. Deben tener carácter acreditativo explícito y resultados inequívocos. Descartá instrumentos exploratorios o procesales.")

    if "GRUPAL" in d4:
        rules.append("- **MODALIDAD GRUPAL:** Los instrumentos DEBEN contemplar dinámica colaborativa, interacción entre pares, roles grupales y producción colectiva. EXCLUÍ instrumentos pensados exclusivamente para resolución individual (ej.: cuestionario individual, evaluación oral individual sin componente grupal).")
    elif "INDIVIDUAL" in d4:
        rules.append("- **MODALIDAD INDIVIDUAL:** Los instrumentos deben poder resolverse de forma autónoma, sin depender de la participación de otros. Descartá instrumentos que requieran trabajo en equipo para funcionar.")

    if "CONCEPTUAL" in d1:
        rules.append("- **CONTENIDO CONCEPTUAL:** Priorizá instrumentos que evalúen comprensión de conceptos, relaciones teóricas, capacidad de argumentar y explicar. Son adecuados: ensayo, mapa conceptual, cuestionario, evaluación oral.")
    elif "PROCEDIMENTAL" in d1:
        rules.append("- **CONTENIDO PROCEDIMENTAL:** Priorizá instrumentos que evalúen la ejecución de pasos, la aplicación práctica y la resolución de problemas en situación real. Son adecuados: evaluación auténtica, estudio de caso, prácticas de laboratorio, trabajo práctico.")
    elif "ACTITUDINAL" in d1:
        rules.append("- **CONTENIDO ACTITUDINAL:** Priorizá instrumentos que evalúen disposiciones, valores, comportamientos observables o reflexión sobre la propia práctica. Son adecuados: portafolio, juego de rol, debate, aprendizaje servicio.")

    if not rules:
        return ""
    return "\n".join(rules)


def _build_dimension_rules_for_design(d1_content="", d3_function="", d4_modality=""):
    """
    Builds explicit dimension-based redaction and structure rules for Step 5 (instrument design).
    Returns a formatted string to inject into the prompt.
    """
    rules = []
    d3 = (d3_function or "").strip().upper()
    d4 = (d4_modality or "").strip().upper()
    d1 = (d1_content or "").strip().upper()

    # --- Modality (impacts grammar throughout the whole instrument) ---
    if "GRUPAL" in d4:
        rules.append("""\
**MODALIDAD GRUPAL — OBLIGATORIO:**
- Redactá TODAS las consignas en segunda persona del PLURAL: "ustedes", "su grupo", "el equipo", "elaboren", "analicen", "discutan", "presenten", etc.
- Incluí instrucciones explícitas sobre cómo organizar el trabajo grupal: asignación de roles, división de tareas y una instancia de plenario o puesta en común.
- Los ítems deben requerir interacción genuina entre los integrantes (no trabajo paralelo que se suma al final).""")
    elif "INDIVIDUAL" in d4:
        rules.append("""\
**MODALIDAD INDIVIDUAL:**
- Redactá las consignas en segunda persona del singular o en forma impersonal: "analizá", "elaborá", "identificá", "el estudiante debe...".
- Cada ítem debe poder resolverse de forma autónoma sin depender de otros.""")

    # --- Function (impacts tone, structure and type of feedback) ---
    if "DIAGN" in d3:
        rules.append("""\
**FUNCIÓN DIAGNÓSTICA — OBLIGATORIO:**
- Utilizá un tono MOTIVADOR y NO PUNITIVO en todas las consignas.
- Incluí un párrafo introductorio aclarando que los resultados se usarán para ajustar la planificación docente y NO para calificar.
- Habilitá la respuesta "no sé" o "nunca lo trabajé" como opción legítima (puede ser una casilla marcable en ítems cerrados).
- Las preguntas deben invitar a mostrar lo que SE SABE, no a evidenciar lo que falta.""")
    elif "FORMATIV" in d3:
        rules.append("""\
**FUNCIÓN FORMATIVA — OBLIGATORIO:**
- Generá consignas que pidan mostrar el RAZONAMIENTO además del resultado (ej.: "Explicá por qué llegaste a esa conclusión").
- Incluí al menos una consigna que invite a identificar dificultades o dudas propias (metacognición).
- Utilizá un tono DIALÓGICO y de acompañamiento, no evaluativo-punitivo.
- En el campo `short_answer` o en la `justification`, incluí un ejemplo de retroalimentación constructiva que el docente podría dar ante una respuesta incorrecta típica.""")
    elif "SUMATIV" in d3:
        rules.append("""\
**FUNCIÓN SUMATIVA — OBLIGATORIO:**
- Explicitá el carácter ACREDITATIVO al inicio del instrumento (en el título o en una consigna marco).
- Utilizá un tono INSTITUCIONAL y formal en todas las consignas.
- Las reglas de resolución deben ser claras, precisas y sin ambigüedades.
- Cada ítem debe tener criterios de corrección inequívocos.""")

    # --- Content type (impacts what kind of thinking is evaluated) ---
    if "CONCEPTUAL" in d1:
        rules.append("**CONTENIDO CONCEPTUAL:** Los ítems deben evaluar comprensión, relaciones entre conceptos y capacidad de argumentar. Evitá consignas de mera reproducción memorística (no preguntes 'qué es X', preguntá 'por qué', 'cómo se relaciona', 'qué implicaría si...').")
    elif "PROCEDIMENTAL" in d1:
        rules.append("**CONTENIDO PROCEDIMENTAL:** Los ítems deben evaluar la ejecución de pasos, la aplicación en situación real y la resolución de problemas concretos. Incluí al menos un ítem que requiera demostrar 'cómo se hace' en un contexto específico.")
    elif "ACTITUDINAL" in d1:
        rules.append("**CONTENIDO ACTITUDINAL:** Los ítems deben evaluar disposiciones, valores o comportamientos observables. Usá situaciones dilema, reflexión sobre la práctica, autoevaluación o análisis de casos con componente ético.")

    if not rules:
        return ""
    return "\n\n".join(rules)


def get_suggestions_prompt(course_summary, objective, dimensions, full_context, feedback="",
                           d1_content="", d3_function="", d4_modality=""):
    feedback_sect = f"\n### AJUSTES REQUERIDOS POR EL DOCENTE (Prioridad alta):\n{feedback}\n" if feedback else ""
    dim_rules = _build_dimension_rules_for_suggestions(d1_content, d3_function, d4_modality)
    dim_rules_sect = f"\n{dim_rules}" if dim_rules else ""
    return f"""
    Tu tarea es proponer 3 instrumentos de evaluación que estén perfectamente alineados con los objetivos y el contexto del curso.

  ### 1. CONTEXTO GENERAL DEL CURSO:
  {course_summary}

  ### 2. OBJETIVOS DE APRENDIZAJE (Taxonomía de Bloom):
  {objective}

  ### 3. DIMENSIONES PEDAGÓGICAS DEFINIDAS:
  {dimensions}

  ### 4. REGLAS DE FILTRADO OBLIGATORIAS POR DIMENSIÓN:
  Aplicá TODAS las siguientes restricciones al seleccionar los 3 instrumentos.
  Estas reglas tienen PRIORIDAD MÁXIMA sobre cualquier otra consideración:{dim_rules_sect}

  ### 5. MATERIALES DEL CURSO, DIRECTRICES Y CATÁLOGO DE INSTRUMENTOS:
  {full_context}
  {feedback_sect}

  ### INSTRUCCIONES CRÍTICAS:
  1. Debes elegir exactamente 3 instrumentos de la "LISTA DE INSTRUMENTOS DISPONIBLES" proporcionada arriba. El valor de "name" en tu respuesta debe ser el NOMBRE EXACTO del catálogo.
  2. Los 3 instrumentos propuestos DEBEN cumplir TODAS las reglas de filtrado de la sección 4. Si un instrumento viola alguna regla (por ejemplo, es individual cuando la modalidad es grupal, o es sumativo cuando la función es diagnóstica), DESCARTALO aunque parezca pertinente por los objetivos.
  3. Basándote en el contexto y las directrices, justifica detalladamente por qué cada uno de estos 3 instrumentos es la mejor opción DADO el perfil completo (función + modalidad + tipo de contenido + nivel de Bloom).
  4. Cada propuesta debe estar justificada pedagógicamente, mencionando cómo se alinea con el nivel de Bloom, las dimensiones definidas y qué directriz institucional cumple.
  5. Responde UNICAMENTE en formato JSON:
  {{
    "suggestions": [
      {{
        "name": "Nombre exacto del catálogo",
        "why": "Justificación detallada citando función evaluativa, modalidad, tipo de contenido, nivel de Bloom y la directriz aplicada.",
        "lim": "Limitación técnica o pedagógica del instrumento en este contexto."
      }}
    ]
  }}"""

# Instruments that require a narrative scenario/context SEPARATE from items.
# For these instruments the LLM must populate the top-level "scenario" field with the
# full narrative/context text so it renders prominently in the UI before the task items.
SCENARIO_INSTRUMENTS = {
    "estudio-caso",    # Case study narrative (Wassermann structure)
    "debate",          # Topic framing + context
    "escape-room",     # World/mission narrative
    "rol",             # Role description + situational context
    "aps",             # Community situation description
    "analisis-fuentes", # Source corpus + situating problem
    "ev-autentica",    # Rol + Audiencia + Tema + Formato (RAFT structure)
}

def get_design_prompt(chosen_instrument, instrument_desc, structured_materials, num_items=5,
                      valid_types=None, feedback="", current_design=None,
                      d1_content="", d3_function="", d4_modality="", instrument_id=""):
    feedback_sect = f"\n### AJUSTES ESPECÍFICOS SOLICITADOS (Prioridad alta):\n{feedback}\n" if feedback else ""

    types_str = ""
    if valid_types:
        types_str = "\n"
        for t in valid_types:
            types_str += f"        - {t['name']}: {t['definition']}\n\n"

    current_design_sect = f"### DISEÑO ACTUAL (Contexto para refinamiento):\n{current_design}\n" if current_design else ""

    # Dimension-specific redaction rules (highest priority)
    dim_rules = _build_dimension_rules_for_design(d1_content, d3_function, d4_modality)
    dim_rules_sect = f"\n{dim_rules}" if dim_rules else ""

    # Instrument-specific structural guidance from pedagogical advisors
    instr_guidance = INSTRUMENT_SPECIFIC_TEMPLATES.get(instrument_id, "")
    instr_guidance_sect = f"\n### GUÍA ESPECÍFICA DEL INSTRUMENTO (OBLIGATORIA):\n{instr_guidance}\n" if instr_guidance else ""

    # Scenario field instructions for narrative-based instruments
    needs_scenario = instrument_id in SCENARIO_INSTRUMENTS
    scenario_instruction = ""
    scenario_json_field = ""
    if needs_scenario:
        scenario_instruction = f"""
  ### CAMPO "scenario" — OBLIGATORIO PARA ESTE INSTRUMENTO (PRIORIDAD MÁXIMA):
  Este instrumento ({chosen_instrument}) REQUIERE un escenario/contexto narrativo RICO, DENSO y DETALLADO.
  El estudiante leerá ÚNICAMENTE este campo antes de responder. REQUISITOS MÍNIMOS (sin excepción):

  - **Extensión**: mínimo 4 párrafos densos. Un escenario de menos de 3 párrafos es INVÁLIDO.
  - **Sujeto concreto**: incluí un personaje, grupo o situación con nombre o identificación y contexto específico (puede ser una persona, un equipo, una organización, una comunidad, etc.).
  - **Datos factuales**: fechas, cifras, circunstancias concretas que sitúen la situación con precisión.
  - **Tensión o dilema**: una situación problemática que requiera análisis, no solo descripción.
  - **Información mixta**: datos relevantes entremezclados con detalles que el estudiante debe discriminar.
  - **Vocabulario técnico** del campo disciplinar presente en los materiales del curso.
  - **Estilo**: narrativo y realista, como un caso profesional auténtico, no un enunciado genérico.

  Los ítems SE REFIEREN a este escenario. NUNCA repitas el escenario dentro de un ítem.
  Escribí primero el escenario completo antes de pensar en los ítems.
"""
        scenario_json_field = '\n    "scenario": "NARRATIVA COMPLETA: mínimo 4 párrafos con personaje concreto, datos factuales, tensión/dilema y vocabulario técnico. Este campo es el núcleo del instrumento.",'

    # For template-driven instruments, tell the model to follow the template count, not num_items
    has_template = instrument_id in INSTRUMENT_SPECIFIC_TEMPLATES
    items_rule = (
        f"Generá EXACTAMENTE los componentes definidos en la GUÍA ESPECÍFICA DEL INSTRUMENTO "
        f"(ignorá el número {num_items} — la guía define la estructura completa)."
        if has_template else
        f"Generá exactamente {num_items} ítems/componentes."
    )

    return f"""### TAREA A REALIZAR:
  Diseñar ítems/componentes de evaluación para un instrumento de tipo: {chosen_instrument}.

  **Descripción del instrumento:**
  {instrument_desc}

  ### REGLAS PEDAGÓGICAS DE REDACCIÓN (PRIORIDAD MÁXIMA — APLICÁ SIEMPRE):
  Estas reglas determinan el TONO, la GRAMÁTICA y la ESTRUCTURA de TODAS las consignas:{dim_rules_sect}
  {instr_guidance_sect}
  {scenario_instruction}

  ### OBJETIVOS DE LA EVALUACIÓN (CON EXTRACTOS DE MATERIALES DEL CURSO):
  {structured_materials}

  ### TIPOS DE PREGUNTAS PERMITIDOS (USÁ SOLO DE ESTA LISTA):
  {types_str}

  {feedback_sect}

  {current_design_sect}

  ### REQUISITOS DE CALIDAD Y FORMATO:
  1. {items_rule}
  2. Cada ítem debe usar OBLIGATORIAMENTE uno de los "TIPOS DE PREGUNTAS PERMITIDOS". El campo "type" debe coincidir EXACTAMENTE con el nombre del tipo.
  3. Para cada ítem, identificá qué objetivos específicos está cubriendo.
  4. **Estructura JSON por tipo de pregunta y respuestas correctas**:
      - **Opción múltiple**: `consiga` (enunciado), `alternativas` (mínimo 4), `correct_index` (0-indexed).
      - **Verdadero/Falso**: `consiga` (afirmación), `correct_boolean` (true/false).
      - **Emparejamiento / Poner en orden**: `consiga` y lista `pairs` con `{{"premise": "P1", "answer": "A1"}}`.
      - **Respuesta breve / Texto lacunar**: `consiga` y respuesta esperada en `short_answer`.
      - **Numérica**: `consiga` y valor exacto en `numerical_value`.
      - **Ensayo / Respuesta abierta / Tarea de producción**: Orientaciones completas en `consiga`. Para instrumentos complejos volcá el contenido del componente aquí en detalle.
  5. Redactá con rigor pedagógico y coherencia con los extractos de materiales.
  6. Asigná una dificultad ("Fácil", "Media", "Difícil").
  7. **Refinamiento parcial**: Si en "AJUSTES ESPECÍFICOS" se menciona un ítem en particular (ej: `[Ítem 1] ...`), REGENERÁ sólo ese ítem y mantené el resto exactamente igual.

  ### FORMATO DE RESPUESTA (JSON ÚNICAMENTE):
  {{
    "title": "Título descriptivo del instrumento",{scenario_json_field}
    "items": [
      {{
        "type": "Nombre exacto del tipo",
        "objectives": ["Obj 1"],
        "consiga": "...",
        "difficulty": "Media",
        "alternativas": ["op A", "op B"],
        "correct_index": 0,
        "correct_boolean": null,
        "pairs": [ {{"premise": "P1", "answer": "A1"}} ],
        "short_answer": "...",
        "numerical_value": null
      }}
    ],
    "justification": "Explica la coherencia pedagógica: función evaluativa, modalidad, tipo de contenido y alineación con Bloom."
  }}"""

def get_rubric_prompt(instrument_content, objective, full_context, feedback=""):
    feedback_sect = f"\n### AJUSTES EN LA RÚBRICA:\n{feedback}\n" if feedback else ""
    return f"""Como experto en evaluación, genera una RÚBRICA ANALÍTICA para el siguiente instrumento.

  ### INSTRUMENTO A EVALUAR:
  {instrument_content}

  ### OBJETIVOS DE APRENDIZAJE:
  {objective}

  ### MARCO PEDAGÓGICO Y REGLAS DE RÚBRICAS:
  {full_context}
  {feedback_sect}

  ### REQUISITOS:
  1. Define criterios claros y discriminativos basados en los materiales del curso.
  2. Los descriptores de niveles deben seguir las reglas de redacción de las DIRECTRICES PEDAGÓGICAS.
  3. Asegura una progresión lógica en los puntajes.

  ### FORMATO DE RESPUESTA (JSON ÚNICAMENTE):
  {{
    "title": "Rúbrica de Evaluación",
    "criteria": [
      {{
        "name": "Nombre del criterio",
        "description": "Qué se evalúa",
        "levels": [
          {{
            "label": "Nivel (ej: Destacado)",
            "score": 10,
            "description": "Descriptor de desempeño"
          }}
        ]
      }}
    ]
  }}"""

def get_correction_prompt(correction_type, correction_label, chosen_instrument, instrument_content, quiz_items_json, objective, full_context, feedback=""):
    feedback_sect = f"\n### AJUSTES SOLICITADOS POR EL DOCENTE:\n{feedback}\n" if feedback else ""

    # Type-specific instructions and JSON schema
    type_instructions = {
        "clave_correccion": {
            "desc": "una CLAVE DE CORRECCIÓN (answer key) que proporcione la respuesta correcta para cada pregunta/ítem del instrumento de evaluación",
            "schema": '''{
    "title": "Clave de corrección para ...",
    "type": "clave_correccion",
    "items": [
      {"question": "Texto de la pregunta", "answer": "Respuesta correcta"}
    ],
    "justification": "Breve justificación pedagógica"
  }'''
        },
        "lista_cotejo": {
            "desc": "una LISTA DE COTEJO (checklist) con criterios observables que verifican la presencia o ausencia de componentes requeridos",
            "schema": '''{
    "title": "Lista de cotejo para ...",
    "type": "lista_cotejo",
    "criteria": [
      {"criterion": "Descripción del criterio observable"}
    ],
    "justification": "Breve justificación pedagógica"
  }'''
        },
        "escala_valoracion": {
            "desc": "una ESCALA DE VALORACIÓN con criterios y niveles de logro que permitan graduar el desempeño de forma ágil",
            "schema": '''{
    "title": "Escala de valoración para ...",
    "type": "escala_valoracion",
    "levels": ["Insuficiente", "Suficiente", "Bueno", "Destacado"],
    "criteria": [
      {"criterion": "Descripción del criterio a evaluar"}
    ],
    "justification": "Breve justificación pedagógica"
  }'''
        },
        "rubrica": {
            "desc": "una RÚBRICA ANALÍTICA con criterios y descriptores detallados para cada nivel de logro",
            "schema": '''{
    "title": "Rúbrica analítica para ...",
    "type": "rubrica",
    "levels": ["Insuficiente", "Suficiente", "Bueno", "Destacado"],
    "criteria": [
      {"criterion": "Nombre del criterio"}
    ],
    "rubric_criteria": [
      {
        "name": "Nombre del criterio",
        "description": "Qué se evalúa",
        "levels": [
          {"label": "Destacado", "score": 4, "description": "Descriptor de desempeño"}
        ]
      }
    ],
    "justification": "Justificación pedagógica detallada"
  }'''
        }
    }

    info = type_instructions.get(correction_type, type_instructions["rubrica"])

    return f"""### TAREA A REALIZAR:
  Genera {info['desc']} para un instrumento de evaluación de tipo "{chosen_instrument}".

  ### OBJETIVOS DE APRENDIZAJE:
  {objective}

  ### INSTRUMENTO DE EVALUACIÓN (Ítems generados previamente):
  {instrument_content}

  ### ÍTEMS DEL CUESTIONARIO (JSON):
  {quiz_items_json}

  ### MARCO PEDAGÓGICO Y DIRECTRICES:
  {full_context}
  {feedback_sect}

  ### INSTRUCCIONES CRÍTICAS:
  1. El instrumento de corrección debe estar perfectamente alineado con los ítems de evaluación proporcionados.
  2. Cada criterio debe ser claro, observable y pedagógicamente fundamentado.
  3. Basa los criterios en los objetivos de aprendizaje y los materiales del curso.
  4. Responde ÚNICAMENTE en formato JSON según el esquema indicado abajo.

  ### FORMATO DE RESPUESTA (JSON ÚNICAMENTE):
  {info['schema']}"""


def get_adjust_item_prompt(item: dict, instruction: str, valid_types=None, objective_json=None) -> str:
    """
    Prompt for step 5.1: refine a SINGLE existing item based on a docent instruction.
    Returns a prompt that produces one InstrumentItem JSON.
    """
    valid_types = valid_types or []
    current_type = item.get("type", "")

    # Build the allowed types section
    if valid_types:
        type_lines = "\n".join(
            f"  - {t.get('name', t.get('id', ''))}: {t.get('description', '')}"
            for t in valid_types
        )
        types_section = f"""### TIPOS DE ÍTEM PERMITIDOS PARA ESTE INSTRUMENTO:
{type_lines}

REGLA: Mantén el tipo actual ("{current_type}") a menos que el docente pida explícitamente cambiarlo.
Si solicita cambiar el tipo, elige ÚNICAMENTE de la lista anterior.
"""
    else:
        types_section = f'Mantén el tipo actual: "{current_type}"\n'

    # Build objectives section
    obj_section = ""
    if objective_json:
        obj_section = f"""### OBJETIVOS DE APRENDIZAJE (contexto):
{objective_json}

"""

    # Build per-type field guidance
    type_field_hints = {
        "Opción múltiple": "Incluye 'alternativas' (lista de strings, 2-5 opciones) y 'correct_index' (int 0-based).",
        "Verdadero/Falso": "Incluye 'correct_boolean' (true o false).",
        "Completar pares": "Incluye 'pairs' (lista de objetos con 'left' y 'right').",
        "Respuesta corta": "Incluye 'short_answer' (string con la respuesta esperada).",
        "Numérico": "Incluye 'numerical_value' (número float).",
        "Ensayo / Respuesta abierta": "No se requieren campos adicionales más allá de 'consiga'.",
        "Oración para completar": "Incluye 'oraciones' (lista de strings con huecos marcados con ___).",
    }
    field_hint = ""
    for key, hint in type_field_hints.items():
        if current_type and key.lower() in current_type.lower():
            field_hint = f"\nPara el tipo actual: {hint}"
            break

    # Current item context
    current_item_json = json.dumps(item, ensure_ascii=False, indent=2)

    return f"""### TAREA:
Aplica el siguiente ajuste a UN ÚNICO ítem de evaluación existente.
Modifica SOLO lo que el docente indica. Mantén todo lo demás igual.

### INSTRUCCIÓN DEL DOCENTE:
{instruction}

### ÍTEM ACTUAL (JSON):
{current_item_json}

{obj_section}{types_section}
### REGLAS DE AJUSTE:
1. Aplica el cambio pedido con precisión pedagógica.
2. Conserva el tipo de ítem a menos que se pida explícitamente cambiarlo.
3. Mantén la dificultad y los puntos salvo que se indique lo contrario.
4. Si el objetivo del ítem cambia, actualiza el campo "objectives" para reflejarlo.
5. Responde ÚNICAMENTE con el JSON del ítem ajustado, sin texto adicional.{field_hint}

### FORMATO DE RESPUESTA (JSON ÚNICAMENTE, un solo ítem):
{{
  "type": "tipo del ítem",
  "objectives": ["objetivo cubierto"],
  "consiga": "texto de la consigna/pregunta ajustada",
  "difficulty": "Fácil|Media|Difícil",
  "points": 1.0
  // ...campos específicos del tipo si aplica
}}
"""
