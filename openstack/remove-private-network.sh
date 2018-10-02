#!/bin/bash
#
# Remove a private network along with its router. This assumes that any instances have already been
# disconnected from the network.
#
# See: http://docs.metacloud.com/latest/user-guide/cli-deleting-network-resources/

function show_help {
cat <<HELP
Usage $0 -n *network-name*
Where:
    -n The name of the network to delete
HELP
}

network_name=

while getopts "h?n:" opt; do
    case "$opt" in
        h|\?)
            show_help
            exit 0
            ;;
        n)  network_name=$OPTARG
            ;;
    esac
done

if [[ -z $network_name ]]; then
    show_help
    exit 1
fi

set -x
NETWORK_ID=$(openstack network list --name $network_name -f value -c ID)

if [[ -z $NETWORK_ID ]]; then
    echo "Could not find network '$network_name'"
    exit 1
fi

ROUTER_PORT_ID=$(openstack port list --network $NETWORK_ID --device-owner network:router_interface -f value -c ID)
SUBNET_ID=$(openstack subnet list --network $NETWORK_ID -f value -c ID)
ROUTER_ID=$(openstack port show $ROUTER_PORT_ID -f value -c device_id)
openstack router remove subnet $ROUTER_ID $SUBNET_ID
openstack network delete $NETWORK_ID
openstack router delete $ROUTER_ID

