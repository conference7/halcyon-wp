### Halcyon

This repository is a Work in Progress and is used for the Blog Series: Halcyon by Marinus Klasen

The goal of this repository is to provide a server configuration for a local development machine.

Featuring:
- nginx-proxy for catching and handling domain requests to other running web services on the machine.
- DNS service for handling domain requests by devices on the network
- a catch-all mailservice
- iPhone and TimeMachine backups
- a VPN service so that you can remotely work on projects as if you are in your office.

Blog series:
1. https://mklasen.com/halcyon-part-1-idea-execution/
2. https://mklasen.com/halcyon-part-2-the-docker-compose-yml-configuration/


### Issues
1. Done: SSH home directory
2. ? VS Code compatibility (the remote host may not meet vs code server's prerequisites for glibc and libstdc++)