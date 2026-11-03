# Development environments

The Moodle 4.3 and 5.2 environments (and the seeded course) belong to the `vektra-moodle` repository
(`docker/compose.moodle.yml`, `env.43`, `env.52`). This directory only adds the plugin to them.

## New environment: bind mount

See `compose.vektradiscuss.yml` (it is a compose override; usage is in its header). The repository is
mounted read-only at `<docroot>/local/vektradiscuss`, so source changes show up immediately; run the
Moodle upgrade after a `version.php` or language-string change.

## Running environment: copy, no recreate

Recreating a container loses the composer dev dependencies installed inside it (PHPUnit, Behat). To add
or refresh the plugin in a running pair:

```bash
# tracked and new files, without .git, .s2s, .github and spike
FILES=$(git ls-files -co --exclude-standard | grep -v '^\(spike\|\.s2s\|\.github\)/')
for t in "vektra-moodle43 /var/www/html/local/vektradiscuss" "vektra-moodle52 /var/www/html/public/local/vektradiscuss"; do
  set -- $t
  docker exec "$1" bash -c "rm -rf $2 && mkdir -p $2"
  COPYFILE_DISABLE=1 tar -cf - $FILES | docker exec -i "$1" tar -xf - -C "$2"
  docker exec "$1" chown -R www-data:www-data "$2"
done
docker exec vektra-moodle43 php /var/www/html/admin/cli/upgrade.php --non-interactive
docker exec vektra-moodle52 php /var/www/html/admin/cli/upgrade.php --non-interactive
```

## PHPUnit in the dev containers

PHPUnit and its vendor tree are present, but the environment is not initialised. In each container add
two lines to `config.php` before `require_once(__DIR__ . '/lib/setup.php');`
(`$CFG->phpunit_prefix = 'phpu_';`, `$CFG->phpunit_dataroot = '/var/moodledata_phpu';`), create that
directory owned by `www-data`, install the `en_AU.UTF-8` locale (`apt-get install locales`, then
`locale-gen`), run `php admin/tool/phpunit/cli/init.php` (from `public/` paths on 5.2) and then
`vendor/bin/phpunit --testsuite local_vektradiscuss_testsuite`. Re-run the init after adding a class.

## Smoke against a Vektra stack

`php local/vektradiscuss/cli/smoke.php --help`. It prints the raw HTTP status of each call and exits 0
(all met), 1 (an expectation failed) or 2 (BLOCKED: an endpoint is not available on that backend).
