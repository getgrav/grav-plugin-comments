# Grav Data Manager Plugin

The **Comments Plugin** for [Grav](http://github.com/getgrav/grav) adds the ability to add comments to pages, and moderate them.

| IMPORTANT!!! This plugin is currently in development as is to be considered a **beta release**.  As such, use this in a production environment **at your own risk!**. More features will be added in the future.

# Installation

The Data plugin is easy to install with GPM.

```
$ bin/gpm install comments
```

Or clone from GitHub and put in the `user/plugins/comments` folder.

# TODO

- Create inferface (with Form?) to allow people to submit a comment to a Page
- Store and email the comment to the emails configured (default to all with admin.super)
- Enable by default on all Pages
- Allow to enable on some taxonomies or page types only
- Allow some pages to disable comments
- Admin interface to moderate comments
- Add ACL permissions so users can moderate comments in admin
