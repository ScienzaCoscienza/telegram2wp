<?php

function get_var($var_name) {

    switch ($var_name) {
        case 'SC_WP_ADMIN_USER':
            return 'XXXXXXXXXX';
            break;
        case 'SC_WP_ADMIN_PWD':
            return 'XXXXXXXXXXXXXXXXXXX';
            break;
        case 'AI_API_USER_KEY':
            return 'XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';
            break;
        case 'AI_API_TOKEN':
            return 'XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';
            break;
        default:
            return false;
    }
	
}