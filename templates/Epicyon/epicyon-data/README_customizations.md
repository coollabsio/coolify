# Epicyon Customizations

## Terms of Service

You can customize the terms of service by editing **accounts/tos.txt**. If it doesn't already exist then you can use **default_tos.txt** as a template.

## About Your Instance

Information about your instance and its origin story can be added by editing **accounts/about.txt**.

## Welcome Message

On the login screen you can provide a custom welcome message by creating the file **accounts/login.txt**. This could be used to show a motd or scheduled maintenance information.

## Login Logo

You can customize the image on the login screen by saving your instance logo to **accounts/login.png**. A background image can also be set for the login screen by adding **accounts/login-background.png**

A custom background image can be supplied for the search screen by adding **accounts/search-background.png**

## Reports Advice

When a moderator report is created the message at the top of the screen can be customized to provide any additional information, advice or alerts. Edit **accounts/report.txt** and add your text.

## Extra Emoji

Extra emoji can be added to the *emoji* directory and you should then update the **emoji/emoji.json** file, which maps the name to the filename (without the .png extension).

Another way to import emoji is to create a text file where each line is the url of the emoji png file and the emoji name, separated by a comma.

```bash
https://somesite/emoji1.png, :emojiname1:
https://somesite/emoji2.png, :emojiname2:
https://somesite/emoji3.png, :emojiname3:
```

Then this can be imported with:

```bash
python3 epicyon.py --import_emoji [textfile]
```

## Themes

If you want to create a new theme then copy the *default* directory within the *theme* directory, rename it to your new theme name, then you can edit the colors and fonts within *theme.json*, and change the icons and banners. Themes are selectable from the graphic design section of the profile screen of the administrator, or of any accounts having the *artist* role.
