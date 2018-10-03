#!/bin/bash
#
# Allocate a floating IP and assign it to an instance. This script assumes the following:
# 1. A single private network exists with DHCP enabled.
# 2. The server will have a single port (interface) on the private network.
# 3. All ports (interfaces) on the public network will be removed after the floating
#    IP is assigned
# 4. If a floating IP is not provided, a new one will be allocated.
#
# See:
# https://help.dreamhost.com/hc/en-us/articles/215912768-Managing-floating-IP-addresses-using-the-OpenStack-CLI

function show_help {
cat <<HELP
Usage $0 -s *server-name* -n *network-name* [-a *ip-address*]
Where:
    -n The name of the network where we will allocate the floating IP
    -s The name of the server that the floating IP will be allocated to
    -a Existing floating IP address to assign
HELP
}

network_name=
server_name=
floating_ip=

while getopts "h?n:s:a:" opt; do
    case "$opt" in
        h|\?)
            show_help
            exit 0
            ;;
        a)  floating_ip=$OPTARG
            ;;
        n)  network_name=$OPTARG
            ;;
        s)  server_name=$OPTARG
            ;;
    esac
done

if [[ -z $server_name || -z $network_name ]]; then
    show_help
    exit 1
fi

set -x
NETWORK_ID=$(openstack network list --name $network_name -f value -c ID)
if [[ -z $NETWORK_ID ]]; then
    echo "Network '$network_name' not found."
    exit 1
fi

SERVER_ID=$(openstack server list --name '^'$server_name'$' -f value -c ID)
if [[ -z $SERVER_ID ]]; then
    echo "Server '$server_name' not found."
    exit 1
fi

INTERNAL_NETWORK=$(openstack network list --internal -f value -c ID)
if [[ -z $INTERNAL_NETWORK ]]; then
    echo "No internal networks found, cannot assign a floating IP."
    exit 1
fi

EXTERNAL_NETWORK=$(openstack network list --external -f value -c ID)
if [[ -z $INTERNAL_NETWORK ]]; then
    echo "No internal networks found, cannot assign a floating IP."
    exit 1
fi

if [[ -z $floating_ip ]]; then
    IP=$(openstack floating ip create $NETWORK_ID -f value -c name)
    if [[ -z $IP ]]; then
        echo "Failed to allocate floating IP on network $network_name ($NETWORK_ID)."
        exit 1
    fi
else
    openstack floating ip show $floating_ip > /dev/null 2>&1
    if [ 0 -ne $? ]; then
        echo "Floating IP does not exist: $floating_ip"
        exit 1
    fi
    IP=$floating_ip
fi

# Add this instance to the internal network (will assign a DHCP address)
openstack server add network $SERVER_ID $INTERNAL_NETWORK
sleep 5
# openstack server list --name '^'$server_name'$' --long -f value -c Networksa | grep $network_name
# Remove the server from the external network to release the IP
openstack server remove network $SERVER_ID $EXTERNAL_NETWORK
# Addign the floating IP
openstack server add floating ip $server_name $IP
while [ 1 -eq $? ]; do
    sleep 5
    openstack server add floating ip $server_name $IP
done
