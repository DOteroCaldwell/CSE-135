/*
 * mod_hw2_environment — the environment demo as a native Apache module.
 *
 *     sudo apxs2 -i -a -c mod_hw2_environment.c
 *
 *     <Location /hw2/module/environment>
 *         SetHandler hw2-environment
 *     </Location>
 *
 * A CGI program inherits its variables as a process environment. A module has
 * no such thing — the same values live in r->subprocess_env, and are only
 * populated on demand by ap_add_common_vars() and ap_add_cgi_vars(). Calling
 * them here is what makes the output comparable to the CGI versions.
 */

#include "httpd.h"
#include "http_config.h"
#include "http_core.h"
#include "http_protocol.h"
#include "ap_config.h"
#include "apr_strings.h"
#include "apr_tables.h"
#include "util_script.h"

/* Withheld for the same reason as the CGI demos: the site is behind HTTP basic
 * auth, so the raw header carries a working credential, and the cookie header
 * carries live session identifiers. */
static int hw2_is_redacted(const char *key)
{
    return strcmp(key, "HTTP_AUTHORIZATION") == 0
        || strcmp(key, "HTTP_PROXY_AUTHORIZATION") == 0
        || strcmp(key, "HTTP_COOKIE") == 0;
}

static int hw2_environment_handler(request_rec *r)
{
    const apr_array_header_t *env_array;
    const apr_table_entry_t *entries;
    int i;

    if (r->handler == NULL || strcmp(r->handler, "hw2-environment") != 0) {
        return DECLINED;
    }

    r->content_type = "text/html; charset=utf-8";
    apr_table_setn(r->headers_out, "Cache-Control", "no-store");
    apr_table_setn(r->headers_out, "X-Content-Type-Options", "nosniff");

    if (r->header_only) {
        return OK;
    }

    /* Populate subprocess_env the way mod_cgi would before exec'ing a script. */
    ap_add_common_vars(r);
    ap_add_cgi_vars(r);

    ap_rputs("<!DOCTYPE html>\n<html lang=\"en\">\n\n<head>\n", r);
    ap_rputs("  <meta charset=\"utf-8\">\n", r);
    ap_rputs("  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n", r);
    ap_rputs("  <link rel=\"stylesheet\" href=\"/css/style.css\">\n", r);
    ap_rputs("  <link rel=\"stylesheet\" href=\"/hw2/hw2.css\">\n", r);
    ap_rputs("  <title>Environment &mdash; Apache module</title>\n", r);
    ap_rputs("</head>\n\n<body>\n  <header>\n", r);
    ap_rputs("    <h1>Environment &mdash; Apache module</h1>\n", r);
    ap_rputs("    <p class=\"tagline\"><span class=\"lang-badge\">C</span> "
             "Diego &mdash; UCSD Wrestling Club</p>\n", r);
    ap_rputs("  </header>\n\n  <main>\n", r);
    ap_rputs("    <table class=\"kv\">\n"
             "      <caption>r-&gt;subprocess_env after ap_add_common_vars() and "
             "ap_add_cgi_vars()</caption>\n      <tbody>\n", r);

    env_array = apr_table_elts(r->subprocess_env);
    entries = (const apr_table_entry_t *) env_array->elts;

    for (i = 0; i < env_array->nelts; i++) {
        const char *key = entries[i].key;
        const char *val = entries[i].val ? entries[i].val : "";

        if (key == NULL) {
            continue;
        }
        if (hw2_is_redacted(key)) {
            val = "[redacted &mdash; see the note below]";
            ap_rprintf(r, "        <tr><th scope=\"row\">%s</th><td>%s</td></tr>\n",
                       ap_escape_html(r->pool, key), val);
            continue;
        }
        ap_rprintf(r, "        <tr><th scope=\"row\">%s</th><td>%s</td></tr>\n",
                   ap_escape_html(r->pool, key), ap_escape_html(r->pool, val));
    }

    ap_rputs("      </tbody>\n    </table>\n", r);
    ap_rputs("    <p class=\"note\"><code>HTTP_AUTHORIZATION</code> and "
             "<code>HTTP_COOKIE</code> are redacted on purpose: the site is behind "
             "HTTP basic auth, so the raw header holds a usable credential.</p>\n", r);
    ap_rputs("    <p><a href=\"/hw2/\">&larr; HW2 index</a></p>\n", r);
    ap_rputs("  </main>\n</body>\n\n</html>\n", r);

    return OK;
}

static void hw2_environment_register_hooks(apr_pool_t *p)
{
    ap_hook_handler(hw2_environment_handler, NULL, NULL, APR_HOOK_MIDDLE);
}

module AP_MODULE_DECLARE_DATA hw2_environment_module = {
    STANDARD20_MODULE_STUFF,
    NULL, NULL, NULL, NULL, NULL,
    hw2_environment_register_hooks
};
