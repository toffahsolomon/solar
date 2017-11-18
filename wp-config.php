<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://codex.wordpress.org/Editing_wp-config.php
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'solar');

/** MySQL database username */
define('DB_USER', 'root');

/** MySQL database password */
define('DB_PASSWORD', '');

/** MySQL hostname */
define('DB_HOST', 'localhost');

/** Database Charset to use in creating database tables. */
define('DB_CHARSET', 'utf8mb4');

/** The Database Collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY',         '~O1+675GZo>}ap>lb}Xx;:-a,8gG`U,v]v2,3No.xlq~SLR.-xvHTeu>zQ|6s*2V');
define('SECURE_AUTH_KEY',  'm93B!7-6%TX2#V$g/|!N<?XQpg^+g$]aC|YvpzQgpO.zP%mX*B$DApKk588+047W');
define('LOGGED_IN_KEY',    '&1FFuW]u*&[WncDdg_mGpttoQ,iOc@lh@,9kBY%A*sG{3%`7&K$A6|-1|ULu-k1U');
define('NONCE_KEY',        '+@)=u=ze50$RRB|1+U*-Lf`+KPB8L|```sI?g1p5{KB-jd|R`n`xl:HcD/(LR2vM');
define('AUTH_SALT',        't%mS^}@Ja9:$@OUaN5pK_BAZ)N/M6=byc&ZB;IM-uK.Qo@M%&v1CEq4Ls#|Q08%$');
define('SECURE_AUTH_SALT', 'mu|TsMgQ8<_$vN|m}Zr+z#T|shUSM#f?yp7&~/0Nh8/A/?e(PAm$5rq.a)|-$Mh#');
define('LOGGED_IN_SALT',   'VK*OjFWb[/,`o%():)2j3{`I/*-VXxn:B[^m0c@,P#*TtYy(<#~UE)XD9Zls24|u');
define('NONCE_SALT',       '_{W8L<XB4yBevbNl+w&[`U5T!9=&GSLM@tk>1Y9)@2G_T}2.fG<n1->za2o&vBWZ');

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix  = 'kuteshopfa_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the Codex.
 *
 * @link https://codex.wordpress.org/Debugging_in_WordPress
 */
define('WP_DEBUG', false);

/* That's all, stop editing! Happy blogging. */

/** Absolute path to the WordPress directory. */
if ( !defined('ABSPATH') )
	define('ABSPATH', dirname(__FILE__) . '/');

/** Sets up WordPress vars and included files. */
require_once(ABSPATH . 'wp-settings.php');
