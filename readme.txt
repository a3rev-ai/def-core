=== Digital Employee Framework - Core ===
Contributors: a3rev
Tags: jwt, api, authentication, sso, bridge, digital employee, ai
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure JWT-based authentication bridge connecting WordPress with external Digital Employee Framework applications via REST API.

== Description ==

Digital Employee Framework - Core is a powerful authentication and API bridge plugin that enables secure communication between your WordPress site and external Digital Employee Framework applications.

= Key Features =

* **JWT Token Authentication** - Issues short-lived, signed context tokens (JWT) for authenticated WordPress users
* **JWKS Endpoint** - Exposes public keys for JWT verification via REST API
* **Session Bridge** - Secure postMessage-based session bridging with configurable allowed origins
* **Single Sign-On (SSO)** - Support for external JWT authentication from other WordPress sites
* **Extensible API** - Clean architecture supporting modules to register additional API tools
* **Cart Synchronization** - WooCommerce cart sync between WordPress and external apps
* **Security First** - Built with security best practices, strict typing, and comprehensive validation

= Use Cases =

* Connect WordPress users to AI-powered Digital Employee applications
* Implement Single Sign-On across multiple WordPress installations
* Bridge e-commerce sites with support forums using shared authentication
* Synchronize WooCommerce cart data with external applications
* Extend functionality with custom API tools via modules

= API Endpoints =

* `/wp-json/a3-ai/v1/jwks` - Public JWKS endpoint for key verification
* `/wp-json/a3-ai/v1/context-token` - Generate signed context tokens (requires authentication)
* Extensible API namespace supporting module-registered endpoints

= Available Modules =

* **bbPress Module** - bbPress forum and topic management API tools
* **a3rev Licenses Module** - WooCommerce license management integration
* **WooCommerce Subscriptions Module** - Subscription management API tools

= Security Features =

* RSA-256 signed JWT tokens
* Configurable token expiration
* Origin validation for cross-domain security
* External JWT verification support
* Nonce-based request validation
* Comprehensive input sanitization

= Developer Friendly =

* Clean, modern PHP 8.0+ codebase
* PSR-4 autoloading compatible
* Strict typing throughout
* Well-documented code
* Extensible via WordPress hooks and filters
* Module development guide included

= Documentation =

Full documentation a module development guide available in the plugin directory:
* `/README.md` - Main documentation
* `/MODULE_DEVELOPMENT.md` - Guide for creating custom modules
* `/examples/` - Example module implementation

== Installation ==

= Minimum Requirements =

* WordPress 6.0 or greater
* PHP version 8.0 or greater
* HTTPS enabled (recommended for production)

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Navigate to Plugins > Add New
3. Upload the plugin zip file
4. Click "Install Now"
5. Activate the plugin

= Manual Installation =

1. Upload the `def-core` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure settings under Settings > Digital Employees

= Configuration =

1. Navigate to **Settings > Digital Employees**
2. Configure **Allowed Origins** for postMessage session bridging
3. (Optional) Configure **External Authentication** for Single Sign-On
4. Enable/disable API tools as needed

== Frequently Asked Questions ==

= What is a Digital Employee? =

A Digital Employee is an AI-powered application that can interact with your WordPress site data through secure APIs, providing intelligent automation and assistance.

= Do I need all the modules? =

No. Install only the modules you need based on your plugins. For example, only install the bbPress module if you're using bbPress.

= Is this plugin secure? =

Yes. The plugin uses industry-standard JWT authentication, RSA-256 signatures, origin validation, and follows WordPress security best practices.

= Can I use this for Single Sign-On? =

Yes. Configure the External Authentication settings to accept JWT tokens from another WordPress site.

= How do I create custom API tools? =

See the `/MODULE_DEVELOPMENT.md` file in the plugin directory for a complete guide on creating custom modules.

= Does this work with WooCommerce? =

Yes. The plugin includes WooCommerce cart synchronization, and there are modules available for Licenses and Subscriptions management.

= What is the JWKS endpoint? =

JWKS (JSON Web Key Set) is a standard endpoint that exposes public keys used to verify JWT signatures. This allows external applications to validate tokens issued by WordPress.

= Can I customize the JWT token expiration? =

Yes. Use the `def_core_token_expiration` filter to customize token lifetime (default is 5 minutes).

== Screenshots ==

1. Settings page - Configure allowed origins and external authentication
2. API Tools management - Enable/disable individual API endpoints
3. Widget integration guide - Instructions for embedding chatbot widget
4. External authentication setup - Single Sign-On configuration

== Changelog ==

= 1.0.0 - 2026-01-02 =
* Initial release
* JWT token generation and validation
* JWKS endpoint for public key verification
* Session bridge with postMessage support
* External JWT authentication (SSO)
* WooCommerce cart synchronization
* Extensible API registry for modules
* Admin settings interface
* Widget integration documentation

== Upgrade Notice ==

= 1.0.0 =
Initial release of Digital Employee Framework - Core.

== Additional Info ==

**Support**: For support inquiries, please visit [a3rev.com](https://a3rev.com/)

**Documentation**: Complete documentation available in plugin directory

**Modules**: Additional functionality available through official modules

**Security**: Report security issues to security@a3rev.com

== Privacy Policy ==

This plugin does not collect or store any personal data beyond what WordPress already collects. JWT tokens contain only the minimum necessary user information and are short-lived by design.

== License ==

This plugin is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or any later version.
