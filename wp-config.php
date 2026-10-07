<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'prueba' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '7+anSX;<C=3*<}DtJHVs;E^o(<>EK_m6&>|o]>Y@QKF]Kz+bN)>8Xc~g- F_XW_]' );
define( 'SECURE_AUTH_KEY',  'qXBuUNj?OGSzu{+uIvv.TIvUs}j)3L/p-Ga5gg!~`Nn0z1Yc;t9Bf: 1k;P||z_I' );
define( 'LOGGED_IN_KEY',    '!f3/~oQMcyr mI#h~%.goCQnLC{ZL/P#4UZtM++J;zzNq+yx,MB7B{B|N HIn(n_' );
define( 'NONCE_KEY',        '!;~jsD3N`LH:YR^[92Xb}i2]k%*IJG#+bHlO*55 IBdAy~d3A^/?vlp`I#~N.r}~' );
define( 'AUTH_SALT',        'W2#MX6wI?Pn.i gcQ*t2gP3f)D*Ro<^3M+[GqR_GkSRlPFwb@HIr$h~0e2X/D8f2' );
define( 'SECURE_AUTH_SALT', 'HO~lL@Uzf >X8(C8q-{aMB^Vt!LwNap^_}p@ZBgOXV_-p1-hl.ve{k&w{r.rtkZl' );
define( 'LOGGED_IN_SALT',   'tbH~ch^Y1u8(SeA7Xe}ekw}m{O|b!k>a(%4]^9e72x>;r{Q5XHF}-*r9E@*x[osk' );
define( 'NONCE_SALT',       ')FL_:C`-6_.?,An.{a:2KuLC8430a>/FJ_aSZn&eC.(@B56bxdfB1*@/{`_];+O?' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
