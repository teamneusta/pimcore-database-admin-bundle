# Pimcore Database Admin Bundle

This bundle integrates [Adminer](https://www.adminer.org/) into the Pimcore 11 admin backend
(just like it was until Pimcore 10).

## Installation

1.  **Require the bundle**

    ```shell
    composer require teamneusta/pimcore-database-admin-bundle
    ```

2.  **Enable the bundle**

    Add the Bundle to your `config/bundles.php`:

    ```php
    Neusta\Pimcore\DatabaseAdminBundle\NeustaPimcoreDatabaseAdminBundle::class => ['all' => true],
    ```
    
3.  **Update your nginx config**

    ```diff
     # Some Admin Modules need this:
     # Server Info, Opcache
    -location ~* ^/admin/external {
    +location ~* ^/admin/(adminer|external) {
         rewrite .* /index.php$is_args$args last;
     }
    ```

## Usage

You will find the database admin in the main menu under »Tools« → »System Info & Tools« → »Database Administration«.

## Contribution

Feel free to open issues for any bug, feature request, or other ideas.

Please remember to create an issue before creating large pull requests.

### Local Development

To develop on your local machine, the vendor dependencies are required.

```shell
bin/composer install
```

We use composer scripts for our main quality tools. They can be executed via the `bin/composer` file as well.

```shell
bin/composer cs:fix
bin/composer phpstan
```
