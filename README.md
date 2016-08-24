# Invoice Generator

This repository accompanies the IBM developerWorks article. It's built with PHP, Silex 2.x and Bootstrap. It uses various services, including Bluemix Object Storage and Bluemix ClearDB. 

It delivers an application that lets users generate PDF invoices online and download or transmit them via email to recipients.

The steps below assume that an Object Storage service and a ClearDB service have been instantiated via the the Bluemix console, and that the user has a valid SendGrid API key.

To deploy this application to your local development environment:

 * Clone the repository to your local system.
 * Run `composer update` to install all dependencies.
 * Create `config.php` with credentials for the various services. Use `config.php.sample` as an example.
 * Create an empty database in your ClearDB instance.
 * Create an empty container named `invoices` in your Object Storage instance.
 * Define a virtual host pointing to the `public` directory, as described in the article.
 
To deploy this application to your Bluemix space:

 * Clone the repository to your local system.
 * Run `composer update` to install all dependencies.
 * Create `config.php` with credentials for the various services. Use `config.php.sample` as an example.
 * Create an empty database in your ClearDB instance.
 * Create an empty container named `invoices` in your Object Storage instance.
 * Update `manifest.yml` with your custom hostname.
 * Push the application to Bluemix and bind Object Storage and ClearDB services to it, as described in the article.
 
A demo instance is available on Bluemix at [http://invoice-generator.mybluemix.net](http://invoice-generator.mybluemix.net).

###### NOTE: The demo instance is available on a public URL and you should be careful to avoid posting sensitive or confidential documents to it. Use the "System Reset" function in the footer to delete all data and restore the system to its default state.