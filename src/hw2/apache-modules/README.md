# HW2 extra credit — Apache modules

Source for the three native Apache 2.4 modules. They are built **on the droplet**
(not in CI), because `apxs2` needs the running server's own headers.

```bash
sudo apt install apache2-dev
cd src/hw2/apache-modules
sudo apxs2 -i -a -c mod_hw2_hello.c
sudo apxs2 -i -a -c mod_hw2_environment.c
sudo apxs2 -i -a -c mod_hw2_echo.c
sudo apachectl configtest && sudo systemctl restart apache2
```

`-i` installs into the module directory, `-a` adds the `LoadModule` line, `-c`
compiles. Then add the `<Location>` blocks from `hw2-modules.conf.sample`.

Write-up: `EXTRA_CREDIT_APACHE_MODULES.md` to be submitted via gradescope.
