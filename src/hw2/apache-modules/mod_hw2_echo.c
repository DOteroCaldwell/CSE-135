/*
 * mod_hw2_echo — the echo demo as a native Apache module.
 *
 *     sudo apxs2 -i -a -c mod_hw2_echo.c
 *
 *     <Location /hw2/module/echo>
 *         SetHandler hw2-echo
 *     </Location>
 *
 * Handles GET, POST, PUT and DELETE. Reading the request body is the one place
 * where a module is genuinely more work than CGI: instead of a pre-filled
 * stdin, the body has to be pulled with the ap_setup_client_block /
 * ap_should_client_block / ap_get_client_block sequence.
 */

#include "httpd.h"
#include "http_config.h"
#include "http_core.h"
#include "http_protocol.h"
#include "ap_config.h"
#include "apr_strings.h"

#define HW2_ECHO_MAX_BODY (1024 * 1024)   /* 1 MiB cap; refuse to buffer more */
#define HW2_ECHO_CHUNK    8192

/*
 * Read the request body into a pool-allocated string.
 * Returns OK, or an HTTP status to send instead.
 */
static int hw2_read_body(request_rec *r, const char **out_body, apr_size_t *out_len)
{
    char chunk[HW2_ECHO_CHUNK];
    char *body = "";
    apr_size_t total = 0;
    long got;
    int rc;

    *out_body = "";
    *out_len = 0;

    rc = ap_setup_client_block(r, REQUEST_CHUNKED_ERROR);
    if (rc != OK) {
        return rc;
    }
    if (!ap_should_client_block(r)) {
        return OK;               /* no body sent — normal for GET and DELETE */
    }

    while ((got = ap_get_client_block(r, chunk, sizeof(chunk))) > 0) {
        if (total + (apr_size_t) got > HW2_ECHO_MAX_BODY) {
            return HTTP_REQUEST_ENTITY_TOO_LARGE;
        }
        /* Text bodies only (form-encoded or JSON), so string concatenation is
         * safe here; a binary body containing NUL would be truncated. */
        body = apr_pstrcat(r->pool, body, apr_pstrmemdup(r->pool, chunk, got), NULL);
        total += (apr_size_t) got;
    }
    if (got < 0) {
        return HTTP_BAD_REQUEST;
    }

    *out_body = body;
    *out_len = total;
    return OK;
}

static void hw2_row(request_rec *r, const char *key, const char *value)
{
    ap_rprintf(r, "        <tr><th scope=\"row\">%s</th><td>%s</td></tr>\n",
               ap_escape_html(r->pool, key),
               ap_escape_html(r->pool, value ? value : ""));
}

static int hw2_echo_handler(request_rec *r)
{
    const char *body;
    apr_size_t body_len;
    const char *ctype;
    char date[APR_RFC822_DATE_LEN];
    int rc;

    if (r->handler == NULL || strcmp(r->handler, "hw2-echo") != 0) {
        return DECLINED;
    }

    rc = hw2_read_body(r, &body, &body_len);
    if (rc != OK) {
        return rc;
    }

    r->content_type = "text/html; charset=utf-8";
    apr_table_setn(r->headers_out, "Cache-Control", "no-store");
    apr_table_setn(r->headers_out, "X-Content-Type-Options", "nosniff");

    if (r->header_only) {
        return OK;
    }

    apr_rfc822_date(date, r->request_time);
    ctype = apr_table_get(r->headers_in, "Content-Type");

    ap_rputs("<!DOCTYPE html>\n<html lang=\"en\">\n\n<head>\n", r);
    ap_rputs("  <meta charset=\"utf-8\">\n", r);
    ap_rputs("  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n", r);
    ap_rputs("  <link rel=\"stylesheet\" href=\"/css/style.css\">\n", r);
    ap_rputs("  <link rel=\"stylesheet\" href=\"/hw2/hw2.css\">\n", r);
    ap_rputs("  <title>Echo &mdash; Apache module</title>\n", r);
    ap_rputs("</head>\n\n<body>\n  <header>\n", r);
    ap_rputs("    <h1>Echo &mdash; Apache module</h1>\n", r);
    ap_rputs("    <p class=\"tagline\"><span class=\"lang-badge\">C</span> "
             "Diego &mdash; UCSD Wrestling Club</p>\n", r);
    ap_rputs("  </header>\n\n  <main>\n", r);

    ap_rputs("    <table class=\"kv\">\n      <caption>Request metadata</caption>\n"
             "      <tbody>\n", r);
    hw2_row(r, "method", r->method);
    hw2_row(r, "contentType", ctype ? ctype : "(none)");
    hw2_row(r, "queryString", r->args ? r->args : "");
    hw2_row(r, "hostname", ap_get_server_name(r));
    hw2_row(r, "generatedAt", date);
    hw2_row(r, "userAgent", apr_table_get(r->headers_in, "User-Agent"));
    hw2_row(r, "clientIp", r->useragent_ip ? r->useragent_ip : "unknown");
    hw2_row(r, "bodyBytes", apr_psprintf(r->pool, "%" APR_SIZE_T_FMT, body_len));
    ap_rputs("      </tbody>\n    </table>\n", r);

    ap_rputs("    <h2>Raw request body</h2>\n    <pre class=\"out\">", r);
    /* ap_escape_html is what keeps a reflected <script> inert. */
    ap_rputs(body_len > 0 ? ap_escape_html(r->pool, body) : "(empty)", r);
    ap_rputs("</pre>\n", r);

    ap_rputs("    <p class=\"note\">Echoed values pass through "
             "<code>ap_escape_html()</code> before output.</p>\n", r);
    ap_rputs("    <p><a href=\"/hw2/echo-form.html\">&larr; Back to the echo form</a></p>\n", r);
    ap_rputs("  </main>\n</body>\n\n</html>\n", r);

    return OK;
}

static void hw2_echo_register_hooks(apr_pool_t *p)
{
    ap_hook_handler(hw2_echo_handler, NULL, NULL, APR_HOOK_MIDDLE);
}

module AP_MODULE_DECLARE_DATA hw2_echo_module = {
    STANDARD20_MODULE_STUFF,
    NULL, NULL, NULL, NULL, NULL,
    hw2_echo_register_hooks
};
