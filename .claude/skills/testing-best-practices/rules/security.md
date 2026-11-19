# Security Tests

Test each security boundary where user input affects authorization, rendered output, or query construction. A defect at such a boundary can be difficult to detect because the feature may continue to work.

Write a test for each of these cases:

- **Cross-tenant access.** Request a record of a different tenant, team, or organization. Read `rules/endpoint-tests.md` for why the response should possibly be `404` rather than `403`.
- **Each unprivileged role.** Use a dataset over the roles that the endpoint must refuse.
- **Escaping user-provided content.** Test escaping in HTML and mail. Include names and every free-text field a template renders. Assert that dangerous characters are escaped and the raw value is absent. Do not assert an exact entity for a quote, because Markdown and mail CSS inliners may decode it.
- **Injection into dynamic query components.** Examples include sort columns, filter fields, and sort directions.
- **An unexpected key** in a payload or configuration array. A merge that accepts every key can set an attribute the user must not control.

```php
it('escapes dangerous content in the notification', function () {
    $organization = Organization::factory()->make([
        'name' => "O'Reilly <script>alert('xss')</script>",
    ]);

    $content = (new QuotaApproaching($organization, 80))->toMail()->render();

    expect($content)
        ->toContain('&lt;script&gt;')
        ->not->toContain("<script>alert('xss')</script>");
});
```

Laravel provides defenses against mass assignment, unauthorized access, and unescaped output. Test that the application applies the appropriate defense to each attribute, route, and template.
