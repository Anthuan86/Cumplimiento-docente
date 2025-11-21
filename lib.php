<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Agrega el reporte de cumplimiento docente a la navegación
 *
 * @param global_navigation $navigation
 */
function local_cumplimiento_docente_extend_navigation(global_navigation $navigation) {
    global $PAGE, $COURSE;

    $context = context_system::instance();

    if (has_capability('local/cumplimiento_docente:view', $context)) {
        $url = new moodle_url('/local/cumplimiento_docente/index.php');
        $node = $navigation->add(
            get_string('pluginname', 'local_cumplimiento_docente'),
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            'cumplimiento_docente',
            new pix_icon('i/report', '')
        );
        $node->showinflatnavigation = true;
    }
}

/**
 * Agrega enlaces a la navegación de administración
 *
 * @param settings_navigation $nav
 * @param context $context
 */
function local_cumplimiento_docente_extend_settings_navigation(settings_navigation $nav, context $context) {
    global $PAGE;

    // Solo en contexto de sistema
    if ($context->contextlevel == CONTEXT_SYSTEM) {
        if (has_capability('local/cumplimiento_docente:view', $context)) {
            $url = new moodle_url('/local/cumplimiento_docente/index.php');

            if ($reportnode = $nav->find('reports', navigation_node::TYPE_CONTAINER)) {
                $reportnode->add(
                    get_string('pluginname', 'local_cumplimiento_docente'),
                    $url,
                    navigation_node::TYPE_SETTING,
                    null,
                    'cumplimiento_docente',
                    new pix_icon('i/report', '')
                );
            }
        }
    }
}
