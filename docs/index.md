## Intention of this plugin

For some customers I provide access to my on-premise Matomo. Every now and then, I need to block new data to come in,
but still provide access to already existing data. So in 2016, I developed this plugin for personal reasons.

## Respect open source community

After 2016 the development stuck. Plugin worked, no changes needed. But this is not how open source works.

When Matomo 4 came out, I feared to update my on-premise instance because this plugin maybe doe not work. Stupid right?

So in 2021 **I investigated a significant effort in test automation** for this plugin. Right now, the logic of this
plugin is covered by integration tests which will run against all supported Matomo versions. In addition to run those
tests against my code changes, I run them once a week against newest developments so in case of problems, I get notified
by CI/CD instead of you.

## GitHub is a mirror

For the Matomo marketplace it is needed to have a GitHub project hosting the code.

Even if for this plugin there is a GitHub project, it is just a kind of mirror to my GitLab project. Changes are
automatically pushed to GitHub, also releases will be automaticcally be created.

Feel free to still fork my GitHub project, even if I do not accept pull requests. I'm looking forward to take over your
changes manually.

## What's tested?

This plugin is tested by using [GitLab CI](https://docs.gitlab.com/ce/ci/).

### PHP compatibility

I make sure all code changes are compatible to:

- php 8.0
- php 7.4
- php 7.3
- php 7.2
- php 7.1
- php 7.0
- php 5.6

### Integration with Matomo

I make sure that the plugin works as expected with this Matomo versions:

- newest Matomo 3
- newest Matomo 4
