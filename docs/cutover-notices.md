# Notices for the cutover

Notices have a start time (`show_after`, empty = at once), a site (`all`, `celestia` or `winterchilla`) and may use `{path}` in the message, which each
site replaces with the address (path and query) of the page being viewed. A link like `https://old.mlpvector.club{path}` therefore leads to the same
page of the other site. Links (`<a href>`) survive the sanitizer, other markup does not. A site only shows the notices for `all` and for itself, so one
shared table can hold the different messages of both sites.

Celestia's built-in "work-in-progress" banner is gone, it is notice A below now.

| | Site | Type | Shown from | Hidden after | Message |
|---|---|---|---|---|---|
| A | `celestia` | warn | now | the cutover | `This website is a work-in-progress, for our current live site please visit <a href="https://mlpvector.club{path}">https://mlpvector.club{path}</a>` |
| B | `celestia` | info | the cutover | far away | `You are on the current version of the site. The previous version is still available in read-only mode at <a href="https://old.mlpvector.club{path}">old.mlpvector.club{path}</a>. If you run into any issues, please contact us using the Contact Us link in the footer.` |
| C | – | – | – | Not a notice any more: Winterchilla's own read-only banner and refusal page link to the same page of the new site from `READ_ONLY_URL` (`https://mlpvector.club`) plus the viewed path. |

Ending A when B starts means nothing has to be done at the moment of the cutover. B and C stay hidden until then.

## Where to create them

- **Now, in Luna's own database** (what Celestia reads before the cutover): A, with Celestia's *Admin → Manage notices* page. Create it right after
  deploying this change or the old banner is simply gone. B can be created at the same time, its start time keeps it hidden.
- **In Winterchilla's production database** (the one Luna reads after the cutover): B and C, with the start time set to the cutover. Winterchilla's notices
  page is read-only (the notices were always added by hand), so insert them with SQL or POST them to `/api/v0/notices` as staff:

```sql
INSERT INTO notices (message_html, type, posted_by, show_after, hide_after, site)
VALUES ('This site is now in read-only mode. To make changes, please visit the new site: <a href="https://mlpvector.club{path}">mlpvector.club{path}</a>.',
        'warn', <staff user id>, '<cutover time>', '2099-01-01', 'winterchilla');
```

The `show_after` and `site` columns come from a migration of each project (Winterchilla's `20261015000000_add_show_after_and_site_to_notices`, Luna's
`2026_10_15_000000_...`), whichever runs first creates them and the other skips them. Winterchilla's own notices keep working with the default `all`.

The host in the messages is a guess for after the cutover (the new site on `mlpvector.club`, the old one on `old.mlpvector.club`): change it in the text
if the domains end up different.
