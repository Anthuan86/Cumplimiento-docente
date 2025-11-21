<?php
defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    // Agregar enlace en el menú de reportes
    $ADMIN->add('reports', new admin_externalpage(
        'local_cumplimiento_docente',
        get_string('pluginname', 'local_cumplimiento_docente'),
        new moodle_url('/local/cumplimiento_docente/index.php'),
        'local/cumplimiento_docente:view'
    ));
}
