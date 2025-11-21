<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = array(

    'local/cumplimiento_docente:view' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
            'coursecreator' => CAP_ALLOW
        )
    ),

);
