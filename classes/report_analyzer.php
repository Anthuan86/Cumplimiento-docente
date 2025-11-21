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
     * Analiza los recursos de un curso por semanas
     * @param int $course_id ID del curso
     * @param string $modalidad_name Nombre de la modalidad
     * @return array Resultados del análisis
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

        // Definir requisitos según modalidad
        $minimo_semanas = $es_distancia ? 8 : 3;
        $minimo_recursos_por_semana = $es_distancia ? 3 : 1;

        // Obtener todas las secciones del curso
        $sections = $DB->get_records('course_sections', ['course' => $course_id], 'section ASC');

        $semanas_analisis = [];
        $semana_actual = null;
        $semana_numero = 0;

        foreach ($sections as $section) {
            $section_name = $section->name ? $section->name : "Sección " . $section->section;

            // Detectar si es una etiqueta de semana
            if (preg_match('/semana\s*(\d+)/i', $section_name, $matches)) {
                // Guardar análisis de semana anterior si existe
                if ($semana_actual !== null) {
                    $semanas_analisis[] = $semana_actual;
                }

                // Iniciar nueva semana
                $semana_numero = intval($matches[1]);
                $semana_actual = [
                    'semana' => $semana_numero,
                    'nombre' => $section_name,
                    'recursos' => [],
                    'tiene_video' => false,
                    'tiene_recurso' => false,
                    'recursos_validos' => 0,
                    'recursos_docente_validos' => 0,
                    'cumple' => false
                ];
            }

            // Analizar recursos de la sección actual
            if ($semana_actual !== null) {
                $recursos = self::get_section_resources($course_id, $section->id, $course_startdate);

                foreach ($recursos as $recurso) {
                    $semana_actual['recursos'][] = $recurso;

                    // Verificar si es un recurso válido (creado después del inicio del curso)
                    if ($recurso['fecha_valida']) {
                        $semana_actual['recursos_validos']++;

                        if ($recurso['es_recurso_docente']) {
                            $semana_actual['tiene_recurso'] = true;
                            $semana_actual['recursos_docente_validos']++;
                        }

                        if ($recurso['es_video']) {
                            $semana_actual['tiene_video'] = true;
                        }
                    }
                }
            }
        }

        // Agregar última semana
        if ($semana_actual !== null) {
            $semanas_analisis[] = $semana_actual;
        }

        // Evaluar cumplimiento de cada semana según la modalidad
        foreach ($semanas_analisis as &$semana) {
            // Verificar que tenga el mínimo de recursos del docente según modalidad
            $cumple_recursos = $semana['recursos_docente_validos'] >= $minimo_recursos_por_semana;

            // Verificar que tenga video
            $cumple_video = $semana['tiene_video'];

            // La semana cumple si tiene ambos requisitos
            $semana['cumple'] = $cumple_recursos && $cumple_video;
        }

        $total_semanas = count($semanas_analisis);
        $semanas_cumplen = 0;
        foreach ($semanas_analisis as $semana) {
            if ($semana['cumple']) {
                $semanas_cumplen++;
            }
        }

        return [
            'requiere_analisis' => true,
            'es_distancia' => $es_distancia,
            'minimo_semanas' => $minimo_semanas,
            'minimo_recursos_por_semana' => $minimo_recursos_por_semana,
            'total_semanas' => $total_semanas,
            'semanas_cumplen' => $semanas_cumplen,
            'cumple_minimo' => $total_semanas >= $minimo_semanas,
            'porcentaje_cumplimiento' => $total_semanas > 0 ? round(($semanas_cumplen / $total_semanas) * 100, 2) : 0,
            'semanas' => $semanas_analisis,
            'course_startdate' => $course_startdate,
            'modalidad_name' => $modalidad_name
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
