<?php

function phenix_automatic_redirect_cpt($cpt) {
    //exclude "professionals" and "locations" custom post types
    unset($cpt['professionals']);
    unset($cpt['locations']);
    return $cpt;
}
add_filter( 'seopress_automatic_redirect_cpt', 'phenix_automatic_redirect_cpt');