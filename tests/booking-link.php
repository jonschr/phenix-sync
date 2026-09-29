<?php

foreach ( array( 'none', ' NONE ', 'http://none', 'https://none/' ) as $value ) {
	if ( '' !== phenixsync_clean_booking_link( $value ) ) {
		throw new RuntimeException( "Invalid booking link was kept: {$value}" );
	}
}

if ( 'https://example.com/book' !== phenixsync_clean_booking_link( 'https://example.com/book' ) ) {
	throw new RuntimeException( 'A valid booking link was changed.' );
}
