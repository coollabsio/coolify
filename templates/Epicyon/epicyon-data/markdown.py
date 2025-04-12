__filename__ = "markdown.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface"


def _markdown_get_sections(markdown: str) -> []:
    """Returns a list of sections for markdown
    """
    if '<code>' not in markdown:
        return [markdown]
    lines = markdown.split('\n')
    sections: list[str] = []
    section_text = ''
    section_active = False
    ctr = 0
    for line in lines:
        if ctr > 0:
            section_text += '\n'

        if not section_active:
            if '<code>' in line:
                section_active = True
                sections.append(section_text)
                section_text = ''
        else:
            if '</code>' in line:
                section_active = False
                sections.append(section_text)
                section_text = ''

        section_text += line
        ctr += 1
    if section_text.strip():
        sections.append(section_text)
    return sections


def _markdown_emphasis_html(markdown: str) -> str:
    """Add italics and bold html markup to the given markdown
    """
    replacements = {
        ' **': ' <b>',
        '** ': '</b> ',
        '**.': '</b>.',
        '**:': '</b>:',
        '**;': '</b>;',
        '?**': '?</b>',
        '\n**': '\n<b>',
        '**,': '</b>,',
        '**\n': '</b>\n',
        '(**': '(<b>)',
        '**)': '</b>)',
        '>**': '><b>',
        '**<': '</b><',
        '>*': '><i>',
        '*<': '</i><',
        ' *': ' <i>',
        '* ': '</i> ',
        '?*': '?</i>',
        '\n*': '\n<i>',
        '*.': '</i>.',
        '*:': '</i>:',
        '*;': '</i>;',
        '(*': '(<i>)',
        '*)': '</i>)',
        '*,': '</i>,',
        '*\n': '</i>\n',
        '(_': '(<u>',
        '_)': '</u>)',
        ' _': ' <u>',
        '_ ': '</u> ',
        '_.': '</u>.',
        '_:': '</u>:',
        '_;': '</u>;',
        '_,': '</u>,',
        '_\n': '</u>\n',
        ' `': ' <em>',
        '`.': '</em>.',
        '`:': '</em>:',
        "`'": "</em>'",
        "(`": "(<em>",
        "`)": "</em>)",
        '`;': '</em>;',
        '`,': '</em>,',
        '`\n': '</em>\n',
        '` ': '</em> '
    }

    sections = _markdown_get_sections(markdown)
    markdown = ''
    for section_text in sections:
        if '<code>' in section_text:
            markdown += section_text
            continue
        for md_str, html in replacements.items():
            section_text = section_text.replace(md_str, html)

        if section_text.startswith('**'):
            section_text = section_text[2:] + '<b>'
        elif section_text.startswith('*'):
            section_text = section_text[1:] + '<i>'
        elif section_text.startswith('_'):
            section_text = section_text[1:] + '<u>'

        if section_text.endswith('**'):
            section_text = section_text[:len(section_text) - 2] + '</b>'
        elif section_text.endswith('*'):
            section_text = section_text[:len(section_text) - 1] + '</i>'
        elif section_text.endswith('_'):
            section_text = section_text[:len(section_text) - 1] + '</u>'

        if section_text.strip():
            markdown += section_text
    return markdown


def _markdown_replace_quotes(markdown: str) -> str:
    """Replaces > quotes with html blockquote
    """
    if '> ' not in markdown:
        return markdown
    lines = markdown.split('\n')
    result = ''
    prev_quote_line = None
    code_section = False
    for line in lines:
        # avoid code sections
        if not code_section:
            if '<code>' in line:
                code_section = True
        else:
            if '</code>' in line:
                code_section = False
        if code_section:
            result += line + '\n'
            continue

        if '> ' not in line:
            result += line + '\n'
            prev_quote_line = None
            continue
        line_str = line.strip()
        if not line_str.startswith('> '):
            result += line + '\n'
            prev_quote_line = None
            continue
        line_str = line_str.replace('> ', '', 1).strip()
        if prev_quote_line:
            new_prev_line = prev_quote_line.replace('</i></blockquote>\n', '')
            result = result.replace(prev_quote_line, new_prev_line) + ' '
            line_str += '</i></blockquote>\n'
        else:
            line_str = '<blockquote><i>' + line_str + '</i></blockquote>\n'
        result += line_str
        prev_quote_line = line_str

    if '</blockquote>\n' in result:
        result = result.replace('</blockquote>\n', '</blockquote>')

    if result.endswith('\n') and \
       not markdown.endswith('\n'):
        result = result[:len(result) - 1]
    return result


def _markdown_replace_links(markdown: str) -> str:
    """Replaces markdown links with html
    Optionally replace image links
    """
    sections = _markdown_get_sections(markdown)
    result = ''
    for section_text in sections:
        if '<code>' in section_text or \
           '](' not in section_text:
            result += section_text
            continue
        sections_links = section_text.split('](')
        ctr = 0
        for link_section in sections_links:
            if ctr == 0:
                ctr += 1
                continue

            if not ('[' in sections_links[ctr - 1] and
                    ')' in link_section):
                ctr += 1
                continue

            link_text = sections_links[ctr - 1].split('[')[-1]
            link_url = link_section.split(')')[0]
            replace_str = '[' + link_text + '](' + link_url + ')'
            link_text = link_text.replace('`', '')
            if '!' + replace_str in section_text:
                html_link = \
                    '<img class="markdownImage" src="' + \
                    link_url + '" alt="' + link_text + '" />'
                section_text = \
                    section_text.replace('!' + replace_str, html_link)
            if replace_str in section_text:
                if not link_url.startswith('#'):
                    # external link
                    html_link = \
                        '<a href="' + link_url + '" target="_blank" ' + \
                        'rel="nofollow noopener noreferrer">' + \
                        link_text + '</a>'
                else:
                    # bookmark
                    html_link = \
                        '<a href="' + link_url + '">' + link_text + '</a>'
                section_text = \
                    section_text.replace(replace_str, html_link)
            ctr += 1
        result += section_text
    return result


def _markdown_replace_misskey(markdown: str) -> str:
    """Replaces misskey animations with emojis
    https://codeberg.org/fediverse/fep/src/branch/main/fep/c16b/fep-c16b.md
    https://akkoma.dev/nbsp/marked-mfm/src/branch/master/docs/syntax.md
    """
    animation_types = {
        'tada': '✨',
        'jelly': '',
        'twitch': '😛',
        'shake': '🫨',
        'spin': '⟳',
        'jump': '🦘',
        'bounce': '⚽',
        'flip': '🙃',
        'x2': '',
        'x3': '',
        'x4': '',
        'font': '',
        'rotate': ''
    }
    if '$[' not in markdown or ']' not in markdown:
        return markdown
    sections = _markdown_get_sections(markdown)
    result = ''
    for section_text in sections:
        if '<code>' in section_text or \
           '$[' not in section_text or \
           ']' not in section_text or \
           ' ' not in section_text:
            result += section_text
            continue
        sections_links = section_text.split('$[')
        ctr = 0
        for link_section in sections_links:
            if ctr == 0:
                ctr += 1
                continue

            if ']' not in link_section:
                ctr += 1
                continue

            misskey_str = link_section.split(']')[0]
            if ' ' not in misskey_str:
                ctr += 1
                continue

            # get the type of animation
            animation_type = misskey_str.split(' ')[0]
            append_emoji = None
            mfm_type = ''
            found = False
            for anim, anim_emoji in animation_types.items():
                if animation_type.startswith(anim):
                    mfm_type = anim
                    append_emoji = anim_emoji
                    found = True
                    break

            if not found:
                ctr += 1
                continue

            animation_text = misskey_str.split(' ', 1)[1]

            orig_str = '$[' + misskey_str + ']'
            if append_emoji:
                animation_text += ' ' + append_emoji
            replace_str = \
                '<span class="mfm-' + mfm_type + '">' + animation_text + \
                '</span>'
            section_text = section_text.replace(orig_str, replace_str)

            ctr += 1
        result += section_text
    return result


def _markdown_replace_bullet_points(markdown: str) -> str:
    """Replaces bullet points
    """
    lines = markdown.split('\n')
    bullet_style = ('* ', ' * ', '- ', ' - ')
    bullet_matched = ''
    start_line = -1
    line_ctr = 0
    changed = False
    code_section = False
    for line in lines:
        if not line.strip():
            # skip blank lines
            line_ctr += 1
            continue

        # skip over code sections
        if not code_section:
            if '<code>' in line:
                code_section = True
        else:
            if '</code>' in line:
                code_section = False
        if code_section:
            line_ctr += 1
            continue

        if not bullet_matched:
            for test_str in bullet_style:
                if line.startswith(test_str):
                    bullet_matched = test_str
                    start_line = line_ctr
                    break
        else:
            if not line.startswith(bullet_matched):
                for index in range(start_line, line_ctr):
                    line_text = lines[index].replace(bullet_matched, '', 1)
                    if index == start_line:
                        lines[index] = \
                            '<ul class="md_list">\n<li>' + line_text + '</li>'
                    elif index == line_ctr - 1:
                        lines[index] = '<li>' + line_text + '</li>\n</ul>'
                    else:
                        lines[index] = '<li>' + line_text + '</li>'
                changed = True
                start_line = -1
                bullet_matched = ''
        line_ctr += 1

    if not changed:
        return markdown

    markdown = ''
    for line in lines:
        markdown += line + '\n'
    return markdown


def _markdown_replace_code(markdown: str) -> str:
    """Replaces code sections within markdown
    """
    lines = markdown.split('\n')
    start_line = -1
    line_ctr = 0
    changed = False
    section_active = False
    url_encode = False
    html_escape_table = {
        "&": "&amp;",
        '"': "&quot;",
        "'": "&apos;",
        ">": "&gt;",
        "<": "&lt;"
    }
    for line in lines:
        if not line.strip():
            # skip blank lines
            line_ctr += 1
            continue
        if line.startswith('```'):
            if not section_active:
                if 'html' in line or 'xml' in line or 'rdf' in line:
                    url_encode = True
                start_line = line_ctr
                section_active = True
            else:
                lines[start_line] = '<code>'
                lines[line_ctr] = '</code>'
                if url_encode:
                    lines[start_line] = '<pre>\n<code>'
                    lines[line_ctr] = '</code>\n</pre>'
                    for line_num in range(start_line + 1, line_ctr):
                        lines[line_num] = \
                            "".join(html_escape_table.get(char, char)
                                    for char in lines[line_num])
                section_active = False
                changed = True
                url_encode = False
        line_ctr += 1

    if not changed:
        return markdown

    markdown = ''
    for line in lines:
        markdown += line + '\n'
    return markdown


def markdown_example_numbers(markdown: str) -> str:
    """Ensures that example numbers in the ActivityPub specification
    document are sequential
    """
    lines = markdown.split('\n')
    example_number = 1
    line_ctr = 0
    for line in lines:
        if not line.strip():
            # skip blank lines
            line_ctr += 1
            continue
        if line.startswith('##') and '## Example ' in line:
            header_str = line.split(' Example ')[0]
            lines[line_ctr] = header_str + ' Example ' + str(example_number)
            example_number += 1
        line_ctr += 1

    markdown = ''
    for line in lines:
        markdown += line + '\n'
    return markdown


def markdown_to_html(markdown: str) -> str:
    """Converts markdown formatted text to html
    """
    markdown = _markdown_replace_misskey(markdown)
    markdown = _markdown_replace_code(markdown)
    markdown = _markdown_replace_bullet_points(markdown)
    markdown = _markdown_replace_quotes(markdown)
    markdown = _markdown_emphasis_html(markdown)
    markdown = _markdown_replace_links(markdown)

    # replace headers
    lines_list = markdown.split('\n')
    html_str = ''
    ctr = 0
    code_section = False
    titles = {
        "h6": '######',
        "h5": '#####',
        "h4": '####',
        "h3": '###',
        "h2": '##',
        "h1": '#'
    }
    for line in lines_list:
        if ctr > 0:
            if not code_section:
                html_str += '<br>\n'
            else:
                html_str += '\n'

        # avoid code sections
        if not code_section:
            if '<code>' in line:
                code_section = True
        else:
            if '</code>' in line:
                code_section = False
        if code_section:
            html_str += line
            ctr += 1
            continue

        for hsh, hashes in titles.items():
            if line.startswith(hashes):
                bookmark_str = line.split(' ', 1)[1].lower().replace(' ', '-')
                line = line.replace(hashes, '').strip()
                line = '<' + hsh + ' id="' + bookmark_str + '">' + \
                    line + '</' + hsh + '>\n'
                ctr = -1
                break
        html_str += line
        ctr += 1

    replacements = (
        ('<code><br>', '<code>'),
        ('</code><br>', '</code>'),
        ('<ul class="md_list"><br>', '<ul class="md_list">'),
        ('</li><br>', '</li>')
    )
    for pair in replacements:
        html_str = html_str.replace(pair[0], pair[1])

    return html_str
