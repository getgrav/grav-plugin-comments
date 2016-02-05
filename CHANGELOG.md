# v1.1.4
## 02/05/2016

1. [](#improved)
    * Added german and polish
    * Avoid listening on onTwigTemplatePaths if not enabled

# v1.1.3
## 01/06/2016

1. [](#improved)
    * Disable captcha by default, added instructions on how to enable it
1. [](#bugfix)
    * Increase priority for onPageInitialized in the comments plugin over the form plugin one to prevent an issue when saving comments

# v1.1.2
## 12/11/2015

1. [](#improved)
    Fix double escaping comments text and author

# v1.1.1
## 12/11/2015

1. [](#improved)
    * Drop the autofocus on the comment form
1. [](#bugfix)
    * Fix double encoding (#12)    

# v1.1.0
## 11/24/2015

1. [](#new)
    * Added french (@codebee-fr) and russian (@joomline) languages
    * Takes advantage of the new nonce support provided by the Form plugin
1. [](#improved)
    * Use date instead of gmdate to respect the server local time (thanks @bovisp)
    * Now works with multilang (thanks @bovisp)
   

# v1.0.2
## 11/13/2015

1. [](#improved)
    * Use nonce
1. [](#improved)
    * Changed form action to work with multilang

# v1.0.1
## 11/11/2015

1. [](#improved)
    * Use onAdminMenu instead of the deprecated onAdminTemplateNavPluginHook
1. [](#bugfix)
    * Fix error when user/data/comments does not exist


# v1.0.0
## 10/21/2015

1. [](#new)
    * Initial Release
