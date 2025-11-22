<?php
namespace local_cumplimiento_docente;

defined('MOODLE_INTERNAL') || die();

/**
 * Clase principal para analizar el cumplimiento docente
 */
class report_analyzer {

    /**
     * Obtiene las modalidades disponibles (segundo nivel de categorías)
     * @return array Array de modalidades
     */
    public static function get_modalidades() {
        global $DB;

        // Obtener categorías de segundo nivel (depth = 2)
        $sql = "SELECT DISTINCT cc.id, cc.name, cc.parent, cc.depth
                FROM {course_categories} cc
                WHERE cc.depth = 2
                AND cc.visible = 1
                ORDER BY cc.name";

        return $DB->get_records_sql($sql);
    }

    /**
     * Obtiene las carreras de una modalidad específica
     * @param int $modalidad_id ID de la modalidad
     * @return array Array de carreras
     */
    public static function get_carreras($modalidad_id = null) {
        global $DB;

        if ($modalidad_id) {
            $sql = "SELECT DISTINCT cc.id, cc.name, cc.parent
                    FROM {course_categories} cc
                    WHERE cc.parent = :modalidad_id
                    AND cc.visible = 1
                    ORDER BY cc.name";
            return $DB->get_records_sql($sql, ['modalidad_id' => $modalidad_id]);
        }

        // Si no hay modalidad, retornar todas las carreras
        $sql = "SELECT DISTINCT cc.id, cc.name, cc.parent
                FROM {course_categories} cc
                WHERE cc.parent > 0
                AND cc.visible = 1
                ORDER BY cc.name";

        return $DB->get_records_sql($sql);
    }

    /**
     * Obtiene los niveles de una carrera específica
     * @param int $carrera_id ID de la carrera
     * @return array Array de niveles
     */
    public static function get_niveles($carrera_id = null) {
        global $DB;

        if ($carrera_id) {
            $sql = "SELECT DISTINCT cc.id, cc.name, cc.parent
                    FROM {course_categories} cc
                    WHERE cc.parent = :carrera_id
                    AND cc.visible = 1
                    ORDER BY cc.name";
            return $DB->get_records_sql($sql, ['carrera_id' => $carrera_id]);
        }

        return [];
    }

    /**
     * Obtiene los cursos según los filtros aplicados
     * @param array $filters Array con los filtros (modalidad, carrera, nivel, docente)
     * @return array Array de cursos
     */
    public static function get_cursos($filters = []) {
        global $DB;

        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.startdate, c.category,
                       cc.name as categoria, cc.parent as categoria_parent, cc.depth,
                       u.id as docente_id, u.firstname, u.lastname, u.email
                FROM {course} c
                INNER JOIN {course_categories} cc ON c.category = cc.id
                LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                LEFT JOIN {role_assignments} ra ON ra.contextid = ctx.id
                LEFT JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('editingteacher', 'teacher')
                LEFT JOIN {user} u ON u.id = ra.userid
                WHERE c.visible = 1
                AND c.id > 1";

        $params = [];

        // Filtro por nivel (más específico)
        if (!empty($filters['nivel'])) {
            $sql .= " AND cc.id = :nivel_id";
            $params['nivel_id'] = $filters['nivel'];
        }
        // Filtro por carrera
        else if (!empty($filters['carrera'])) {
            // Obtener la carrera y todos sus hijos (niveles)
            $sql .= " AND (cc.id = :carrera_id OR cc.parent = :carrera_id2)";
            $params['carrera_id'] = $filters['carrera'];
            $params['carrera_id2'] = $filters['carrera'];
        }
        // Filtro por modalidad (segundo nivel)
        else if (!empty($filters['modalidad'])) {
            // Obtener todas las categorías descendientes de la modalidad
            $sql .= " AND cc.path LIKE :modalidad_path";

            // Obtener el path de la modalidad
            $modalidad = $DB->get_record('course_categories', ['id' => $filters['modalidad']], 'path');
            if ($modalidad) {
                $params['modalidad_path'] = $modalidad->path . '/%';
            }
        }

        // Filtro por docente
        if (!empty($filters['docente'])) {
            $sql .= " AND u.id = :docente_id";
            $params['docente_id'] = $filters['docente'];
        }

        $sql .= " ORDER BY c.fullname, u.lastname, u.firstname";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Obtiene la modalidad de un curso (categoría de segundo nivel en la jerarquía)
     * @param int $course_id ID del curso
     * @return object|null Objeto con la información de la modalidad
     */
    public static function get_course_modalidad($course_id) {
        global $DB;

        // Obtener la categoría del curso
        $course = $DB->get_record('course', ['id' => $course_id], 'category', MUST_EXIST);
        $category = $DB->get_record('course_categories', ['id' => $course->category], '*', MUST_EXIST);

        // Buscar la modalidad en el path (depth = 2)
        $path_parts = explode('/', trim($category->path, '/'));

        // El segundo elemento del path (índice 1) es la modalidad (depth=2)
        if (count($path_parts) >= 2) {
            $modalidad_id = $path_parts[1];
            $modalidad = $DB->get_record('course_categories', ['id' => $modalidad_id], 'id, name');

            if ($modalidad) {
                return (object)[
                    'id' => $modalidad->id,
                    'modalidad_name' => $modalidad->name
                ];
            }
        }

        return null;
    }

    /**
     * Obtiene la duración del curso desde los campos personalizados
     * @param int $course_id ID del curso
     * @return int|null Duración en horas (32 o 48) o null si no está definida
     */
    public static function get_course_duration($course_id) {
        global $DB;

        try {
            // Buscar el campo personalizado "duracion" o similar
            $sql = "SELECT cf.id
                    FROM {customfield_field} cf
                    WHERE cf.shortname LIKE '%duracion%'
                    OR cf.shortname LIKE '%duration%'
                    OR cf.name LIKE '%duración%'
                    OR cf.name LIKE '%horas%'
                    LIMIT 1";

            $field = $DB->get_record_sql($sql);

            if (!$field) {
                return null;
            }

            // Obtener el valor para este curso
            $data = $DB->get_record('customfield_data', [
                'fieldid' => $field->id,
                'instanceid' => $course_id
            ]);

            if (!$data) {
                return null;
            }

            // El valor es 1 para 32 horas, 2 para 48 horas
            if ($data->value == 1 || $data->value == '1') {
                return 32;
            } else if ($data->value == 2 || $data->value == '2') {
                return 48;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Analiza las secciones de un curso por semanas
     *
     * Este método analiza las siguientes secciones:
     * 1. "Recursos o Material de Apoyo" - con sus semanas
     * 2. "Actividades de Aprendizaje" - con sus semanas
     *
     * Modalidades analizadas:
     * - Presencial, Semipresencial, Híbrida, En Línea: Mínimo 3 semanas, 1 recurso/semana
     * - Distancia: Mínimo 8 semanas, 3 recursos/semana
     *
     * Todas requieren: 1 video por semana + fecha de edición posterior al inicio del curso
     *
     * @param int $course_id ID del curso
     * @param string $modalidad_name Nombre de la modalidad
     * @return array Resultados del análisis con ambas secciones
     */
    public static function analyze_course_resources($course_id, $modalidad_name) {
        global $DB;

        // Obtener la fecha de inicio del curso
        $course = $DB->get_record('course', ['id' => $course_id], 'startdate', MUST_EXIST);
        $course_startdate = $course->startdate;

        // Verificar si la modalidad requiere análisis por semanas
        $modalidades_con_semanas = ['Presencial', 'Semipresencial', 'Híbrida', 'En Línea', 'Distancia'];
        $requiere_analisis = false;
        $es_distancia = false;

        foreach ($modalidades_con_semanas as $mod) {
            if (stripos($modalidad_name, $mod) !== false) {
                $requiere_analisis = true;
                if (stripos($modalidad_name, 'Distancia') !== false) {
                    $es_distancia = true;
                }
                break;
            }
        }

        if (!$requiere_analisis) {
            return [
                'requiere_analisis' => false,
                'mensaje' => 'Esta modalidad no requiere análisis por semanas'
            ];
        }

        // Obtener duración del curso (32 o 48 horas)
        $duracion_curso = self::get_course_duration($course_id);

        // Detectar tipo de modalidad específico
        $es_presencial = stripos($modalidad_name, 'Presencial') !== false && stripos($modalidad_name, 'Semi') === false;

        // Definir requisitos según modalidad y duración
        if ($es_distancia) {
            // Modalidad Distancia
            $minimo_semanas = 8;
            $minimo_recursos_por_semana = 3;
            $minimo_actividades_por_semana = 1;
        } else if ($es_presencial && $duracion_curso == 48) {
            // Modalidad Presencial de 48 horas
            $minimo_semanas = 5;
            $minimo_recursos_por_semana = 1;
            $minimo_actividades_por_semana = 4;
        } else {
            // Otras modalidades (Presencial 32h, Semipresencial, Híbrida, En Línea)
            $minimo_semanas = 3;
            $minimo_recursos_por_semana = 1;

            if ($duracion_curso == 32) {
                $minimo_actividades_por_semana = 2;
            } else if ($duracion_curso == 48) {
                $minimo_actividades_por_semana = 4;
            } else {
                // Si no hay duración definida, usar valor por defecto
                $minimo_actividades_por_semana = 2;
            }
        }

        // Analizar sección "Recursos o Material de Apoyo"
        $material_apoyo = self::analyze_section_by_weeks(
            $course_id,
            ['Material de Apoyo', 'Recursos', 'Material'],
            $course_startdate,
            $minimo_recursos_por_semana
        );

        // Analizar sección "Actividades de Aprendizaje" con reglas específicas
        $actividades_aprendizaje = self::analyze_activities_section(
            $course_id,
            ['Actividades de Aprendizaje', 'Actividades'],
            $course_startdate,
            $minimo_actividades_por_semana
        );

        // Si ninguna sección fue encontrada, retornar error
        if (!$material_apoyo['encontrada'] && !$actividades_aprendizaje['encontrada']) {
            return [
                'requiere_analisis' => false,
                'mensaje' => 'No se encontraron las secciones "Recursos o Material de Apoyo" ni "Actividades de Aprendizaje" en este curso.'
            ];
        }

        return [
            'requiere_analisis' => true,
            'es_distancia' => $es_distancia,
            'minimo_semanas' => $minimo_semanas,
            'minimo_recursos_por_semana' => $minimo_recursos_por_semana,
            'minimo_actividades_por_semana' => $minimo_actividades_por_semana,
            'duracion_curso' => $duracion_curso,
            'course_startdate' => $course_startdate,
            'modalidad_name' => $modalidad_name,
            'secciones' => [
                'material_apoyo' => $material_apoyo,
                'actividades_aprendizaje' => $actividades_aprendizaje
            ]
        ];
    }

    /**
     * Analiza una sección específica del curso por semanas
     *
     * @param int $course_id ID del curso
     * @param array $section_patterns Patrones para buscar la sección
     * @param int $course_startdate Fecha de inicio del curso
     * @param int $minimo_recursos_por_semana Mínimo de recursos requeridos por semana
     * @return array Análisis de la sección
     */
    private static function analyze_section_by_weeks($course_id, $section_patterns, $course_startdate, $minimo_recursos_por_semana) {
        global $DB;

        // PASO 1: Buscar la sección usando los patrones proporcionados
        $sql_section = "SELECT id, name, section
                        FROM {course_sections}
                        WHERE course = :course_id
                        AND visible = 1
                        ORDER BY section ASC";

        $all_sections = $DB->get_records_sql($sql_section, ['course_id' => $course_id]);

        $section_id = null;
        $section_name = '';

        // Buscar la sección que coincida con alguno de los patrones
        foreach ($all_sections as $section) {
            $current_section_name = $section->name ? $section->name : '';

            foreach ($section_patterns as $pattern) {
                if (stripos($current_section_name, $pattern) !== false) {
                    $section_id = $section->id;
                    $section_name = $current_section_name;
                    break 2; // Salir de ambos foreach
                }
            }
        }

        // Si no se encuentra la sección, retornar que no fue encontrada
        if (!$section_id) {
            return [
                'encontrada' => false,
                'nombre' => '',
                'semanas' => [],
                'total_semanas' => 0,
                'semanas_cumplen' => 0,
                'cumple_minimo' => false,
                'porcentaje_cumplimiento' => 0
            ];
        }

        // PASO 2: Obtener la secuencia de módulos de la sección
        $section_data = $DB->get_record('course_sections',
            ['id' => $section_id],
            'id, sequence'
        );

        if (!$section_data || empty($section_data->sequence)) {
            return [
                'encontrada' => true,
                'nombre' => $section_name,
                'semanas' => [],
                'total_semanas' => 0,
                'semanas_cumplen' => 0,
                'cumple_minimo' => false,
                'porcentaje_cumplimiento' => 0
            ];
        }

        // Obtener IDs de módulos en el orden correcto
        $module_ids = explode(',', $section_data->sequence);
        $module_ids = array_filter($module_ids); // Eliminar vacíos

        if (empty($module_ids)) {
            return [
                'encontrada' => true,
                'nombre' => $section_name,
                'semanas' => [],
                'total_semanas' => 0,
                'semanas_cumplen' => 0,
                'cumple_minimo' => false,
                'porcentaje_cumplimiento' => 0
            ];
        }

        // PASO 3: Obtener los módulos en el orden de la secuencia
        list($in_sql, $params) = $DB->get_in_or_equal($module_ids, SQL_PARAMS_NAMED, 'modid');
        $params['course_id'] = $course_id;

        $sql = "SELECT cm.id, cm.section, cm.module, cm.instance, cm.added as timeadded,
                       m.name as modname, cs.section as section_number
                FROM {course_modules} cm
                INNER JOIN {modules} m ON m.id = cm.module
                INNER JOIN {course_sections} cs ON cs.id = cm.section
                WHERE cm.course = :course_id
                AND cm.id $in_sql
                AND cm.visible = 1
                AND cm.deletioninprogress = 0";

        $modules = $DB->get_records_sql($sql, $params);

        // Ordenar módulos según la secuencia de la sección
        $ordered_modules = [];
        foreach ($module_ids as $module_id) {
            if (isset($modules[$module_id])) {
                $ordered_modules[] = $modules[$module_id];
            }
        }

        // Array asociativo para almacenar semanas por número (evita duplicados)
        $semanas_por_numero = [];
        $semana_numero_actual = null;
        $debug_log = []; // Para debugging

        // PASO 4: Procesar cada módulo en el orden correcto de la sección
        foreach ($ordered_modules as $module) {
            $es_etiqueta_semana = false;

            // Si es una etiqueta (label), verificar si es una etiqueta de semana
            if ($module->modname === 'label') {
                try {
                    $label = $DB->get_record('label', ['id' => $module->instance], 'intro, name');
                    if ($label) {
                        // Buscar "Semana X" en el contenido de la etiqueta
                        $label_content = $label->intro . ' ' . $label->name;
                        if (preg_match('/semana\s*(\d+)/i', strip_tags($label_content), $matches)) {
                            $es_etiqueta_semana = true;
                            $semana_numero_actual = intval($matches[1]);

                            // Logging para debug
                            $ya_existe = isset($semanas_por_numero[$semana_numero_actual]);
                            $debug_log[] = "Módulo {$module->id}: Detectada etiqueta Semana {$semana_numero_actual}" . ($ya_existe ? ' (YA EXISTE)' : ' (NUEVA)');

                            // Si esta semana no existe, crearla
                            if (!isset($semanas_por_numero[$semana_numero_actual])) {
                                $semanas_por_numero[$semana_numero_actual] = [
                                    'semana' => $semana_numero_actual,
                                    'nombre' => 'Semana ' . $semana_numero_actual,
                                    'recursos' => [],
                                    'tiene_video' => false,
                                    'tiene_recurso' => false,
                                    'recursos_validos' => 0,
                                    'recursos_docente_validos' => 0,
                                    'cumple' => false
                                ];
                            }
                            // Si ya existe, simplemente cambiamos el puntero a esa semana
                            // Los recursos siguientes se agregarán a la semana existente
                        }
                    }
                } catch (\Exception $e) {
                    // Continuar si hay error al obtener la etiqueta
                    continue;
                }
            }

            // Si hay una semana actual y no es una etiqueta de semana, analizar como recurso
            if ($semana_numero_actual !== null && !$es_etiqueta_semana) {
                $recurso = self::analyze_module_resource($module, $course_startdate);
                if ($recurso) {
                    // Agregar recurso a la semana actual
                    $semanas_por_numero[$semana_numero_actual]['recursos'][] = $recurso;

                    // Verificar si es un recurso válido (creado después del inicio del curso)
                    if ($recurso['fecha_valida']) {
                        $semanas_por_numero[$semana_numero_actual]['recursos_validos']++;

                        if ($recurso['es_recurso_docente']) {
                            $semanas_por_numero[$semana_numero_actual]['tiene_recurso'] = true;
                            $semanas_por_numero[$semana_numero_actual]['recursos_docente_validos']++;
                        }

                        if ($recurso['es_video']) {
                            $semanas_por_numero[$semana_numero_actual]['tiene_video'] = true;
                        }
                    }
                }
            }
        }

        // Log del estado del array asociativo antes de convertir
        $debug_log[] = "Array asociativo tiene " . count($semanas_por_numero) . " elementos: " . implode(', ', array_keys($semanas_por_numero));

        // Convertir array asociativo a array indexado y ordenar por número de semana
        $semanas_analisis = [];
        ksort($semanas_por_numero); // Ordenar por número de semana
        foreach ($semanas_por_numero as $num_semana => $semana) {
            $debug_log[] = "Agregando al array final: Semana {$num_semana}";
            $semanas_analisis[] = $semana;
        }

        $debug_log[] = "Array final tiene " . count($semanas_analisis) . " elementos";

        // Evaluar cumplimiento de cada semana según la modalidad
        foreach ($semanas_analisis as &$semana) {
            // Verificar que tenga el mínimo de recursos del docente según modalidad
            $cumple_recursos = $semana['recursos_docente_validos'] >= $minimo_recursos_por_semana;

            // Verificar que tenga video
            $cumple_video = $semana['tiene_video'];

            // La semana cumple si tiene ambos requisitos
            $semana['cumple'] = $cumple_recursos && $cumple_video;
        }
        unset($semana); // IMPORTANTE: Romper la referencia para evitar bugs

        $total_semanas = count($semanas_analisis);
        $semanas_cumplen = 0;
        foreach ($semanas_analisis as $semana) {
            if ($semana['cumple']) {
                $semanas_cumplen++;
            }
        }

        // Debug: verificar que el array sigue correcto después de los loops
        $numeros_finales = array_map(function($s) { return $s['semana']; }, $semanas_analisis);
        $debug_log[] = "Números después de evaluación: " . implode(', ', $numeros_finales);

        return [
            'encontrada' => true,
            'nombre' => $section_name,
            'total_semanas' => $total_semanas,
            'semanas_cumplen' => $semanas_cumplen,
            'porcentaje_cumplimiento' => $total_semanas > 0 ? round(($semanas_cumplen / $total_semanas) * 100, 2) : 0,
            'semanas' => $semanas_analisis,
            'debug_log' => $debug_log  // Para debugging temporal
        ];
    }

    /**
     * Analiza la sección de Actividades de Aprendizaje con reglas específicas
     *
     * @param int $course_id ID del curso
     * @param array $section_patterns Patrones para buscar la sección
     * @param int $course_startdate Fecha de inicio del curso
     * @param int $minimo_actividades_por_semana Mínimo de actividades requeridas por semana
     * @return array Análisis de la sección
     */
    private static function analyze_activities_section($course_id, $section_patterns, $course_startdate, $minimo_actividades_por_semana) {
        global $DB;

        // PASO 1: Buscar la sección usando los patrones proporcionados
        $sql_section = "SELECT id, name, section
                        FROM {course_sections}
                        WHERE course = :course_id
                        AND visible = 1
                        ORDER BY section ASC";

        $all_sections = $DB->get_records_sql($sql_section, ['course_id' => $course_id]);

        $section_id = null;
        $section_name = '';

        // Buscar la sección que coincida con alguno de los patrones
        foreach ($all_sections as $section) {
            $current_section_name = $section->name ? $section->name : '';

            foreach ($section_patterns as $pattern) {
                if (stripos($current_section_name, $pattern) !== false) {
                    $section_id = $section->id;
                    $section_name = $current_section_name;
                    break 2;
                }
            }
        }

        // Si no se encuentra la sección, retornar que no fue encontrada
        if (!$section_id) {
            return [
                'encontrada' => false,
                'nombre' => '',
                'semanas' => [],
                'total_semanas' => 0,
                'semanas_cumplen' => 0,
                'cumple_minimo' => false,
                'porcentaje_cumplimiento' => 0
            ];
        }

        // PASO 2: Obtener la secuencia de módulos de la sección
        $section_data = $DB->get_record('course_sections',
            ['id' => $section_id],
            'id, sequence'
        );

        if (!$section_data || empty($section_data->sequence)) {
            return [
                'encontrada' => true,
                'nombre' => $section_name,
                'semanas' => [],
                'total_semanas' => 0,
                'semanas_cumplen' => 0,
                'cumple_minimo' => false,
                'porcentaje_cumplimiento' => 0
            ];
        }

        // Obtener IDs de módulos en el orden correcto
        $module_ids = explode(',', $section_data->sequence);
        $module_ids = array_filter($module_ids);

        if (empty($module_ids)) {
            return [
                'encontrada' => true,
                'nombre' => $section_name,
                'semanas' => [],
                'total_semanas' => 0,
                'semanas_cumplen' => 0,
                'cumple_minimo' => false,
                'porcentaje_cumplimiento' => 0
            ];
        }

        // PASO 3: Obtener los módulos en el orden de la secuencia
        list($in_sql, $params) = $DB->get_in_or_equal($module_ids, SQL_PARAMS_NAMED, 'modid');
        $params['course_id'] = $course_id;

        $sql = "SELECT cm.id, cm.section, cm.module, cm.instance, cm.added as timeadded, cm.completion,
                       m.name as modname, cs.section as section_number
                FROM {course_modules} cm
                INNER JOIN {modules} m ON m.id = cm.module
                INNER JOIN {course_sections} cs ON cs.id = cm.section
                WHERE cm.course = :course_id
                AND cm.id $in_sql
                AND cm.visible = 1
                AND cm.deletioninprogress = 0";

        $modules = $DB->get_records_sql($sql, $params);

        // Ordenar módulos según la secuencia de la sección
        $ordered_modules = [];
        foreach ($module_ids as $module_id) {
            if (isset($modules[$module_id])) {
                $ordered_modules[] = $modules[$module_id];
            }
        }

        // Array asociativo para almacenar semanas por número (evita duplicados)
        $semanas_por_numero = [];
        $semana_numero_actual = null;
        $debug_log = [];

        // PASO 4: Procesar cada módulo en el orden correcto de la sección
        foreach ($ordered_modules as $module) {
            $es_etiqueta_semana = false;

            // Si es una etiqueta (label), verificar si es una etiqueta de semana
            if ($module->modname === 'label') {
                try {
                    $label = $DB->get_record('label', ['id' => $module->instance], 'intro, name');
                    if ($label) {
                        // Buscar "Semana X" en el contenido de la etiqueta
                        $label_content = $label->intro . ' ' . $label->name;
                        if (preg_match('/semana\s*(\d+)/i', strip_tags($label_content), $matches)) {
                            $es_etiqueta_semana = true;
                            $semana_numero_actual = intval($matches[1]);

                            $ya_existe = isset($semanas_por_numero[$semana_numero_actual]);
                            $debug_log[] = "Módulo {$module->id}: Detectada etiqueta Semana {$semana_numero_actual}" . ($ya_existe ? ' (YA EXISTE)' : ' (NUEVA)');

                            // Si esta semana no existe, crearla
                            if (!isset($semanas_por_numero[$semana_numero_actual])) {
                                $semanas_por_numero[$semana_numero_actual] = [
                                    'semana' => $semana_numero_actual,
                                    'nombre' => 'Semana ' . $semana_numero_actual,
                                    'actividades' => [],
                                    'total_actividades' => 0,
                                    'actividades_validas' => 0,
                                    'cumple' => false
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            // Si hay una semana actual y no es una etiqueta de semana, analizar como actividad
            if ($semana_numero_actual !== null && !$es_etiqueta_semana) {
                $actividad = self::analyze_activity_module($module, $course_startdate);
                if ($actividad) {
                    // Agregar actividad a la semana actual
                    $semanas_por_numero[$semana_numero_actual]['actividades'][] = $actividad;
                    $semanas_por_numero[$semana_numero_actual]['total_actividades']++;

                    // Contar actividades válidas
                    if ($actividad['es_actividad_valida']) {
                        $semanas_por_numero[$semana_numero_actual]['actividades_validas']++;
                    }
                }
            }
        }

        $debug_log[] = "Array asociativo tiene " . count($semanas_por_numero) . " elementos: " . implode(', ', array_keys($semanas_por_numero));

        // Convertir array asociativo a array indexado y ordenar por número de semana
        $semanas_analisis = [];
        ksort($semanas_por_numero);
        foreach ($semanas_por_numero as $num_semana => $semana) {
            $debug_log[] = "Agregando al array final: Semana {$num_semana}";
            $semanas_analisis[] = $semana;
        }

        $debug_log[] = "Array final tiene " . count($semanas_analisis) . " elementos";

        // Evaluar cumplimiento de cada semana
        foreach ($semanas_analisis as &$semana) {
            $semana['cumple'] = $semana['actividades_validas'] >= $minimo_actividades_por_semana;
        }
        unset($semana);

        $total_semanas = count($semanas_analisis);
        $semanas_cumplen = 0;
        foreach ($semanas_analisis as $semana) {
            if ($semana['cumple']) {
                $semanas_cumplen++;
            }
        }

        $numeros_finales = array_map(function($s) { return $s['semana']; }, $semanas_analisis);
        $debug_log[] = "Números después de evaluación: " . implode(', ', $numeros_finales);

        return [
            'encontrada' => true,
            'nombre' => $section_name,
            'total_semanas' => $total_semanas,
            'semanas_cumplen' => $semanas_cumplen,
            'porcentaje_cumplimiento' => $total_semanas > 0 ? round(($semanas_cumplen / $total_semanas) * 100, 2) : 0,
            'semanas' => $semanas_analisis,
            'debug_log' => $debug_log
        ];
    }

    /**
     * Obtiene los recursos de una sección específica
     * @param int $course_id ID del curso
     * @param int $section_id ID de la sección
     * @param int $course_startdate Fecha de inicio del curso
     * @return array Array de recursos
     */
    private static function get_section_resources($course_id, $section_id, $course_startdate) {
        global $DB;

        $recursos = [];

        // Tipos de módulos que consideramos como recursos del docente
        $modulos_recurso = ['page', 'resource', 'label', 'folder', 'url', 'book', 'forum'];

        $sql = "SELECT cm.id, cm.module, cm.instance, cm.added as timeadded,
                       m.name as modname
                FROM {course_modules} cm
                INNER JOIN {modules} m ON m.id = cm.module
                WHERE cm.course = :course_id
                AND cm.section = :section_id
                AND cm.visible = 1
                ORDER BY cm.id";

        $modules = $DB->get_records_sql($sql, [
            'course_id' => $course_id,
            'section_id' => $section_id
        ]);

        foreach ($modules as $module) {
            $es_recurso_docente = in_array($module->modname, $modulos_recurso);
            $es_video = false;
            $fecha_modificacion = $module->timeadded;
            $nombre = '';

            // Obtener detalles específicos según el tipo de módulo
            try {
                $instancia = $DB->get_record($module->modname, ['id' => $module->instance]);

                if ($instancia) {
                    $nombre = isset($instancia->name) ? $instancia->name : '';

                    // Verificar fecha de modificación
                    if (isset($instancia->timemodified)) {
                        $fecha_modificacion = $instancia->timemodified;
                    }

                    // Detectar videos
                    if ($module->modname === 'resource') {
                        // Obtener el archivo asociado
                        $fs = get_file_storage();
                        $context = \context_module::instance($module->id);
                        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder', false);

                        foreach ($files as $file) {
                            $mimetype = $file->get_mimetype();
                            if (strpos($mimetype, 'video/') === 0) {
                                $es_video = true;
                                break;
                            }
                        }
                    } else if ($module->modname === 'url' && isset($instancia->externalurl)) {
                        // Detectar URLs de video (YouTube, Vimeo, etc.)
                        $url = $instancia->externalurl;
                        if (preg_match('/(youtube|youtu\.be|vimeo|dailymotion)/i', $url)) {
                            $es_video = true;
                        }
                    } else if ($module->modname === 'label' && isset($instancia->intro)) {
                        // Detectar videos embebidos en etiquetas
                        if (preg_match('/<video|<iframe.*?(youtube|vimeo)/i', $instancia->intro)) {
                            $es_video = true;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Continuar si hay error al obtener la instancia
            }

            $fecha_valida = $fecha_modificacion > $course_startdate;

            $recursos[] = [
                'id' => $module->id,
                'tipo' => $module->modname,
                'nombre' => $nombre,
                'es_recurso_docente' => $es_recurso_docente,
                'es_video' => $es_video,
                'fecha_modificacion' => $fecha_modificacion,
                'fecha_valida' => $fecha_valida,
                'fecha_inicio_curso' => $course_startdate
            ];
        }

        return $recursos;
    }

    /**
     * Analiza un módulo individual como recurso
     * @param object $module Objeto del módulo
     * @param int $course_startdate Fecha de inicio del curso
     * @return array|null Información del recurso o null si no es un recurso válido
     */
    private static function analyze_module_resource($module, $course_startdate) {
        global $DB;

        // Tipos de módulos que consideramos como recursos del docente
        $modulos_recurso = ['page', 'resource', 'label', 'folder', 'url', 'book', 'forum'];

        $es_recurso_docente = in_array($module->modname, $modulos_recurso);
        $es_video = false;
        $fecha_modificacion = $module->timeadded;
        $nombre = '';

        // Obtener detalles específicos según el tipo de módulo
        try {
            $instancia = $DB->get_record($module->modname, ['id' => $module->instance]);

            if ($instancia) {
                $nombre = isset($instancia->name) ? $instancia->name : '';

                // Verificar fecha de modificación
                if (isset($instancia->timemodified)) {
                    $fecha_modificacion = $instancia->timemodified;
                }

                // Detectar videos (mejorado para detectar múltiples fuentes)
                $es_video = self::detect_video_in_module($module, $instancia);
            }
        } catch (\Exception $e) {
            // Si hay error, retornar null
            return null;
        }

        $fecha_valida = $fecha_modificacion > $course_startdate;

        return [
            'id' => $module->id,
            'tipo' => $module->modname,
            'nombre' => $nombre,
            'es_recurso_docente' => $es_recurso_docente,
            'es_video' => $es_video,
            'fecha_modificacion' => $fecha_modificacion,
            'fecha_valida' => $fecha_valida,
            'fecha_inicio_curso' => $course_startdate
        ];
    }

    /**
     * Detecta si un módulo contiene o es un video
     * Detecta: archivos de video, URLs de video (YouTube, Vimeo, etc.), videos embebidos
     * @param object $module Objeto del módulo
     * @param object $instancia Instancia del módulo
     * @return bool True si contiene video, false si no
     */
    private static function detect_video_in_module($module, $instancia) {
        $es_video = false;

        try {
            // 1. Detectar archivos de video (resource)
            if ($module->modname === 'resource') {
                $fs = get_file_storage();
                $context = \context_module::instance($module->id);
                $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder', false);

                foreach ($files as $file) {
                    $mimetype = $file->get_mimetype();
                    $filename = strtolower($file->get_filename());

                    // Detectar por MIME type
                    if (strpos($mimetype, 'video/') === 0) {
                        $es_video = true;
                        break;
                    }

                    // Detectar por extensión de archivo (backup)
                    $video_extensions = ['.mp4', '.avi', '.mov', '.wmv', '.flv', '.mkv', '.webm', '.m4v', '.mpeg', '.mpg'];
                    foreach ($video_extensions as $ext) {
                        if (substr($filename, -strlen($ext)) === $ext) {
                            $es_video = true;
                            break 2;
                        }
                    }
                }
            }

            // 2. Detectar URLs de video (url)
            if ($module->modname === 'url' && isset($instancia->externalurl)) {
                $url = $instancia->externalurl;

                // Patrones de URLs de video más completos
                $video_patterns = [
                    '/youtube\.com\/watch/i',
                    '/youtu\.be\//i',
                    '/youtube\.com\/embed/i',
                    '/youtube\.com\/v\//i',
                    '/vimeo\.com\//i',
                    '/dailymotion\.com/i',
                    '/dai\.ly\//i',
                    '/wistia\.com/i',
                    '/loom\.com/i',
                    '/panopto\./i',
                    '/kaltura\./i',
                    '/viddler\.com/i',
                    '/twitch\.tv/i',
                    '/facebook\.com.*\/videos/i',
                    '/fb\.watch/i',
                    '/instagram\.com.*\/p\//i',
                    '/tiktok\.com/i',
                    '/drive\.google\.com.*\/file/i', // Google Drive videos
                    '/\.mp4(\?|$)/i', // Direct video links
                    '/\.avi(\?|$)/i',
                    '/\.mov(\?|$)/i',
                    '/\.webm(\?|$)/i',
                ];

                foreach ($video_patterns as $pattern) {
                    if (preg_match($pattern, $url)) {
                        $es_video = true;
                        break;
                    }
                }
            }

            // 3. Detectar videos embebidos en etiquetas (label)
            if ($module->modname === 'label' && isset($instancia->intro)) {
                $content = $instancia->intro;

                // Primero, buscar URLs de video en el contenido (links, texto plano, etc.)
                $video_url_patterns = [
                    '/youtube\.com\/watch/i',
                    '/youtu\.be\//i',
                    '/youtube\.com\/embed/i',
                    '/vimeo\.com\//i',
                    '/dailymotion\.com/i',
                    '/wistia\.com/i',
                    '/loom\.com/i',
                    '/panopto\./i',
                    '/kaltura\./i',
                    '/drive\.google\.com.*\/file/i',
                    '/\.mp4(\?|"|\'|>|$)/i',
                    '/\.avi(\?|"|\'|>|$)/i',
                    '/\.mov(\?|"|\'|>|$)/i',
                    '/\.webm(\?|"|\'|>|$)/i',
                ];

                foreach ($video_url_patterns as $pattern) {
                    if (preg_match($pattern, $content)) {
                        $es_video = true;
                        break;
                    }
                }

                // Si no se encontró URL, buscar tags HTML de video embebido
                if (!$es_video) {
                    $embed_patterns = [
                        '/<video[\s>]/i',                          // Tag <video>
                        '/<iframe.*?youtube/i',                    // YouTube iframe
                        '/<iframe.*?vimeo/i',                      // Vimeo iframe
                        '/<iframe.*?dailymotion/i',                // Dailymotion iframe
                        '/<iframe.*?wistia/i',                     // Wistia iframe
                        '/<iframe.*?loom/i',                       // Loom iframe
                        '/<iframe.*?panopto/i',                    // Panopto iframe
                        '/<embed.*?type=["\']video/i',             // Embed tag con video
                        '/\[video\]/i',                            // Shortcode [video]
                        '/src=["\'].*?\.(mp4|avi|mov|webm)/i',     // Source con extensión de video
                    ];

                    foreach ($embed_patterns as $pattern) {
                        if (preg_match($pattern, $content)) {
                            $es_video = true;
                            break;
                        }
                    }
                }
            }

            // 4. Detectar videos embebidos en páginas (page)
            if ($module->modname === 'page' && isset($instancia->content)) {
                $content = $instancia->content;

                // Primero, buscar URLs de video en el contenido
                $video_url_patterns = [
                    '/youtube\.com\/watch/i',
                    '/youtu\.be\//i',
                    '/youtube\.com\/embed/i',
                    '/vimeo\.com\//i',
                    '/dailymotion\.com/i',
                    '/wistia\.com/i',
                    '/loom\.com/i',
                    '/panopto\./i',
                    '/kaltura\./i',
                    '/drive\.google\.com.*\/file/i',
                    '/\.mp4(\?|"|\'|>|$)/i',
                    '/\.avi(\?|"|\'|>|$)/i',
                    '/\.mov(\?|"|\'|>|$)/i',
                    '/\.webm(\?|"|\'|>|$)/i',
                ];

                foreach ($video_url_patterns as $pattern) {
                    if (preg_match($pattern, $content)) {
                        $es_video = true;
                        break;
                    }
                }

                // Si no se encontró URL, buscar tags HTML de video embebido
                if (!$es_video) {
                    $embed_patterns = [
                        '/<video[\s>]/i',
                        '/<iframe.*?youtube/i',
                        '/<iframe.*?vimeo/i',
                        '/<iframe.*?dailymotion/i',
                        '/<iframe.*?wistia/i',
                        '/<iframe.*?loom/i',
                        '/<iframe.*?panopto/i',
                        '/<embed.*?type=["\']video/i',
                        '/\[video\]/i',
                        '/src=["\'].*?\.(mp4|avi|mov|webm)/i',
                    ];

                    foreach ($embed_patterns as $pattern) {
                        if (preg_match($pattern, $content)) {
                            $es_video = true;
                            break;
                        }
                    }
                }
            }

            // 5. Detectar en libros (book) - pueden tener capítulos con videos
            if ($module->modname === 'book') {
                global $DB;
                $chapters = $DB->get_records('book_chapters', ['bookid' => $instancia->id]);

                foreach ($chapters as $chapter) {
                    if (isset($chapter->content)) {
                        $content = $chapter->content;

                        // Buscar URLs de video en el contenido del capítulo
                        $video_url_patterns = [
                            '/youtube\.com\/watch/i',
                            '/youtu\.be\//i',
                            '/youtube\.com\/embed/i',
                            '/vimeo\.com\//i',
                            '/dailymotion\.com/i',
                            '/wistia\.com/i',
                            '/loom\.com/i',
                            '/panopto\./i',
                            '/kaltura\./i',
                            '/drive\.google\.com.*\/file/i',
                            '/\.mp4(\?|"|\'|>|$)/i',
                            '/\.avi(\?|"|\'|>|$)/i',
                            '/\.mov(\?|"|\'|>|$)/i',
                            '/\.webm(\?|"|\'|>|$)/i',
                        ];

                        foreach ($video_url_patterns as $pattern) {
                            if (preg_match($pattern, $content)) {
                                $es_video = true;
                                break 2;
                            }
                        }

                        // Si no se encontró URL, buscar tags HTML
                        if (!$es_video) {
                            $embed_patterns = [
                                '/<video[\s>]/i',
                                '/<iframe.*?(youtube|vimeo|dailymotion|wistia|loom|panopto)/i',
                                '/src=["\'].*?\.(mp4|avi|mov|webm)/i',
                            ];

                            foreach ($embed_patterns as $pattern) {
                                if (preg_match($pattern, $content)) {
                                    $es_video = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            // Si hay error, continuar (el video no se detectó)
        }

        return $es_video;
    }

    /**
     * Analiza un módulo como actividad de aprendizaje
     * Valida: nombre, tipo, interacciones y condiciones de finalización
     *
     * @param object $module Objeto del módulo
     * @param int $course_startdate Fecha de inicio del curso
     * @return array|null Información de la actividad o null si no es válida
     */
    private static function analyze_activity_module($module, $course_startdate) {
        global $DB;

        // Tipos de módulos que consideramos como actividades
        $tipos_actividades = ['assign', 'workshop', 'hvp', 'forum', 'quiz', 'survey', 'lesson', 'choice', 'feedback'];

        // Si no es un tipo de actividad, retornar null
        if (!in_array($module->modname, $tipos_actividades)) {
            return null;
        }

        try {
            // Obtener detalles de la actividad
            $instancia = $DB->get_record($module->modname, ['id' => $module->instance]);

            if (!$instancia) {
                return null;
            }

            $nombre = isset($instancia->name) ? $instancia->name : '';
            $fecha_modificacion = $module->timeadded;

            if (isset($instancia->timemodified)) {
                $fecha_modificacion = $instancia->timemodified;
            }

            // Validar nombre: debe contener "Actividad" seguido de número
            $cumple_nombre = preg_match('/actividad\s*\(?(\d+)\)?/i', $nombre);

            // Validar que tenga condiciones de finalización configuradas
            // completion: 0 = sin seguimiento, 1 = manual, 2 = automático
            $tiene_completion = isset($module->completion) && $module->completion > 0;

            // Validar que esté después de la fecha de inicio del curso
            $fecha_valida = $fecha_modificacion > $course_startdate;

            // Verificar interacciones (simplificado)
            $tiene_interacciones = self::check_activity_has_interactions($module, $instancia);

            // La actividad es válida si cumple TODOS los criterios
            $es_actividad_valida = $cumple_nombre && $tiene_completion && $fecha_valida;

            return [
                'id' => $module->id,
                'tipo' => $module->modname,
                'nombre' => $nombre,
                'cumple_nombre' => $cumple_nombre,
                'tiene_completion' => $tiene_completion,
                'tiene_interacciones' => $tiene_interacciones,
                'fecha_modificacion' => $fecha_modificacion,
                'fecha_valida' => $fecha_valida,
                'es_actividad_valida' => $es_actividad_valida
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Verifica si una actividad tiene interacciones de estudiantes
     *
     * @param object $module Objeto del módulo
     * @param object $instancia Instancia de la actividad
     * @return bool True si tiene interacciones
     */
    private static function check_activity_has_interactions($module, $instancia) {
        global $DB;

        try {
            // Verificar según el tipo de actividad
            switch ($module->modname) {
                case 'assign': // Tareas
                    $count = $DB->count_records('assign_submission', ['assignment' => $instancia->id]);
                    return $count > 0;

                case 'forum': // Foros
                    $discussions = $DB->get_records('forum_discussions', ['forum' => $instancia->id]);
                    return count($discussions) > 0;

                case 'quiz': // Cuestionarios
                    $count = $DB->count_records('quiz_attempts', ['quiz' => $instancia->id]);
                    return $count > 0;

                case 'workshop': // Talleres
                    $count = $DB->count_records('workshop_submissions', ['workshopid' => $instancia->id]);
                    return $count > 0;

                case 'lesson': // Lecciones
                    $count = $DB->count_records('lesson_attempts', ['lessonid' => $instancia->id]);
                    return $count > 0;

                case 'choice': // Consultas
                    $count = $DB->count_records('choice_answers', ['choiceid' => $instancia->id]);
                    return $count > 0;

                case 'feedback': // Retroalimentación
                    $count = $DB->count_records('feedback_completed', ['feedback' => $instancia->id]);
                    return $count > 0;

                default:
                    // Para otros tipos, asumimos que tienen interacciones si están configurados
                    return true;
            }
        } catch (\Exception $e) {
            // Si hay error, no podemos confirmar interacciones
            return false;
        }
    }

    /**
     * Genera el reporte completo según los filtros
     * @param array $filters Array con los filtros aplicados
     * @return array Reporte completo
     */
    public static function generate_report($filters = []) {
        $cursos = self::get_cursos($filters);
        $reporte = [];

        foreach ($cursos as $curso) {
            $modalidad = self::get_course_modalidad($curso->id);
            $modalidad_name = $modalidad ? $modalidad->modalidad_name : 'Sin modalidad';

            $analisis = self::analyze_course_resources($curso->id, $modalidad_name);

            $reporte[] = [
                'curso' => $curso,
                'modalidad' => $modalidad_name,
                'analisis' => $analisis
            ];
        }

        return $reporte;
    }

    /**
     * Obtiene lista de docentes
     * @return array Array de docentes
     */
    public static function get_docentes() {
        global $DB;

        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                FROM {user} u
                INNER JOIN {role_assignments} ra ON ra.userid = u.id
                INNER JOIN {role} r ON r.id = ra.roleid
                WHERE r.shortname IN ('editingteacher', 'teacher')
                AND u.deleted = 0
                AND u.suspended = 0
                ORDER BY u.lastname, u.firstname";

        return $DB->get_records_sql($sql);
    }
}
