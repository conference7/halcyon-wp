#!/bin/bash

echo "**** installing gcompat ****"

# This is what make vscode remote-ssh work
apk add gcompat libstdc++ curl

# As alpine by default use busybox and some common utilities behave differently, like grep
apk add grep dropbear-scp dropbear-ssh

# Add zsh if using zsh shell
apk add zsh

echo "**** Done installing ****"