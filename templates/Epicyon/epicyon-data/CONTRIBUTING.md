## License

By submitting code, documentation or artwork you agree that it will be licensed under the GNU AGPL license version 3 or later.

## Security Vulnerability Disclosure

Create an issue on https://gitlab.com/bashrc2/epicyon/issues. If the vulnerability is especially sensitive then send an XMPP message to **bob@libreserver.org** or a Matrix message to **@bob:conduit.libreserver.org**.

## Code of Conduct

The code of conduct can be found [here](code-of-conduct.md).

## Submitting Bugs, Feature Requests or Merge Requests

Submit to https://gitlab.com/bashrc2/epicyon/issues

You can also post patches in the old-fashioned style via email to **bob@libreserver.org**. Include **[Epicyon]** in the subject line, otherwise it may be ignored.

## Development Style

Try not to add extra package dependencies. Dependency proliferation is something which this project is trying to avoid. Large numbers of dependencies create maintenance problems.

Avoid javascript. The web interface should be usable in a Tor browser with NoScript enabled and also in Lynx.

If you are adding or changing the web interface, make sure that your changes make sense when viewed within a Lynx browser.

## Translations

Translations can be added in json format to the **translations** directory. No other modifications to the software are required. You can use **en.json** as a template for creating a new translations file.

## Themes

To create a new theme you can manually edit **epicyon.css** on a running server, then when you are happy with the changes create a new function within **theme.py** and call it from **setTheme**.

If you need customized icons then add them within **img/icons** as a subdirectory.

Add translations for the name of your new theme, then within **webinterface.py** edit the **themesDropdown** string to add your theme.
