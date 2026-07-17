# Custom API access for non-Super-Users

By default, Joomla only allows API access to and mints API tokens for Super Users. If you want to use a non-Super-User account with Grafida you will need to follow the guide below to configure your site and Grafida itself.

1. Log into your site as a Super User.
1. Go to Users Groups and create a new user group with the name **API Access**. Make sure the Group Parent is set to Public.
1. Go to System, Setup, Global Configuration.
1. Click the Permissions tab.
1. Click on the API Access user group.
1. Set the following permissions:
   * **Web Services Login**: Allowed.

You can now edit your non-Super-User user accounts who need access to Grafida and add them to the API Access user group _in addition to_ their normal user group (e.g. Author). 

## Caveats

Make sure the Public user group's permissions are all set to the value "Not Set". If you have set anything to "Denied", congratulations, you have broken Joomla's access control model and with it your site. Go fix it before doing anything else.

Your actions are limited by the user groups your user belongs to _in addition to_ the API Access user group. Authors, for example, cannot change the publish status of an article; only Publishers and above can. This means that you will perceive some features in Grafida "not working". They work fine; Joomla simply denied you access because your Permissions do not allow what you're trying to do.

Some media manager features may not work. Again, this is Joomla denying you access based on your Permissions.

Grafida cannot get the Unicode Aliases setting of your site. The value of this setting is only available to Super Users. As a result, you will have to edit the article alias manually if you're using non-ASCII characters in the title; in any other case, you'd end up with an alias that's partial, or nonsensical. This is not a bug, it's a limitation in Joomla itself.
