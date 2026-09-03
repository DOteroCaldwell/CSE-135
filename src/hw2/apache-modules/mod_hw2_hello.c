/*
 * mod_hw2_hello — the hello-html demo as a native Apache module.
 *
 * Build and enable:
 *     sudo apxs2 -i -a -c mod_hw2_hello.c
 *
 * Activate on a URL by giving that location this handler:
 *     <Location /hw2/module/hello>
 *         SetHandler hw2-hello
 *     </Location>
 *
 * The contrast with the CGI versions is the point: there is no process fork,
 * no environment block to marshal and no stdout to parse. The module runs
 * inside the server and writes straight into the response brigade.
 */

#include "httpd.h"
#include "http_config.h"
#include "http_core.h"
#include "http_log.h"
#include "http_protocol.h"
#include "ap_config.h"
#include "apr_strings.h"

#define HW2_TEAM "Diego &mdash; UCSD Wrestling Club"

static int hw2_hello_handler(request_rec *r)
{
    char date[APR_RFC822_DATE_LEN];

    /* Every handler in the chain is offered every request; decline the ones
     * that were not routed here by SetHandler. */
    if (r->handler == NULL || strcmp(r->handler, "hw2-hello") != 0) {
        return DECLINED;
    }

    if (r->method_number != M_GET) {
        return HTTP_METHOD_NOT_ALLOWED;
    }

    r->content_type = "text/html; charset=utf-8";
    apr_table_setn(r->headers_out, "Cache-Control", "no-store");
    apr_table_setn(r->headers_out, "X-Content-Type-Options", "nosniff");

    if (r->header_only) {
        return OK;
    }

    apr_rfc822_date(date, r->request_time);

    ap_rputs("<!DOCTYPE html>\n<html lang=\"en\">\n\n<head>\n", r);
    ap_rputs("  <meta charset=\"utf-8\">\n", r);
    ap_rputs("  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n", r);
    ap_rputs("  <link rel=\"stylesheet\" href=\"/css/style.css\">\n", r);
    ap_rputs("  <link rel=\"stylesheet\" href=\"/hw2/hw2.css\">\n", r);
    ap_rputs("  <title>Hello HTML &mdash; Apache module</title>\n", r);
    ap_rputs("</head>\n\n<body>\n  <header>\n", r);
    ap_rputs("    <h1>Hello HTML &mdash; Apache module</h1>\n", r);
    ap_rputs("    <p class=\"tagline\"><span class=\"lang-badge\">C</span> " HW2_TEAM "</p>\n", r);
    ap_rputs("  </header>\n\n  <main>\n", r);

    ap_rprintf(r, "    <p>Hello from %s.</p>\n", HW2_TEAM);
    ap_rputs("    <table class=\"kv\">\n      <caption>Response details</caption>\n      <tbody>\n", r);
    ap_rprintf(r, "        <tr><th scope=\"row\">Language</th><td>C (Apache %s)</td></tr>\n",
               ap_escape_html(r->pool, ap_get_server_banner()));
    ap_rprintf(r, "        <tr><th scope=\"row\">Generated at</th><td>%s</td></tr>\n",
               ap_escape_html(r->pool, date));
    ap_rprintf(r, "        <tr><th scope=\"row\">Your IP address</th><td>%s</td></tr>\n",
               ap_escape_html(r->pool, r->useragent_ip ? r->useragent_ip : "unknown"));
    ap_rputs("      </tbody>\n    </table>\n", r);

    ap_rputs("    <p class=\"note\">Served by a compiled Apache module, in-process. "
             "No CGI fork, no environment marshalling.</p>\n", r);
    ap_rputs("    <p><a href=\"/hw2/\">&larr; HW2 index</a></p>\n", r);
    ap_rputs("  </main>\n</body>\n\n</html>\n", r);

    return OK;
}

static void hw2_hello_register_hooks(apr_pool_t *p)
{
    ap_hook_handler(hw2_hello_handler, NULL, NULL, APR_HOOK_MIDDLE);
}

module AP_MODULE_DECLARE_DATA hw2_hello_module = {
    STANDARD20_MODULE_STUFF,
    NULL,                       /* per-directory config creator   */
    NULL,                       /* per-directory config merger    */
    NULL,                       /* per-server  config creator     */
    NULL,                       /* per-server  config merger      */
    NULL,                       /* command table                  */
    hw2_hello_register_hooks
};
