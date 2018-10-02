#!/bin/bash
#
# Create a network submet with a router that allows outbound traffic to the public network but
# by default does not allow incoming traffic. An additional network is needed when all;ocating 
#
# See https://help.dreamhost.com/hc/en-us/articles/235230887-Creating-and-managing-private-networks-with-the-OpenStack-CLI

function show_help {
cat <<HELP
Usage $0 -p *network-name* -e *external-network* -c *cidr* -d *dns-server*
Where:
    -p The name of the internal (private) network (e.g., private-subnet).
    -e The name of the external public network (e.g., lakeeffect-199.109.195)
    -c The CIDR for the internal network (e.g., 10.10.0.0/24)
    -d The DNS servers to use for the private subnet. May be used multiple times.
HELP
}

private_net=
external_net=
private_cidr=
dns_servers=

while getopts "h?p:e:c:d:" opt; do
    case "$opt" in
        h|\?)
            show_help
            exit 0
            ;;
        p)  private_net=$OPTARG
            ;;
        e)  external_net=$OPTARG
            ;;
        c)  private_cidr=$OPTARG
            ;;
        d)  dns_servers=${dns_servers}${dns_servers:-" "}"--dns-nameserver $OPTARG"
            ;;
    esac
done

if [[ -z $private_net || -z $external_net || -z $private_cidr ]]; then
    show_help
    exit 1
fi

set -x
# Create the network
openstack network create --no-share --internal --availability-zone-hint cbls --description $private_net $private_net
# Create the subnet on the network
openstack subnet create $dns_servers --dhcp --gateway auto --ip-version 4 --subnet-range $private_cidr --network $private_net ${private_net}-subnet
# Create a router
openstack router create ${private_net}-router
# Set the external gateway to the public network
openstack router set --external-gateway $external_net ${private_net}-router
# Connect the subnet to the router. Note that this will automatically create an interface port
# owned by the router with a x.x.x.1 IP
openstack router add subnet ${private_net}-router ${private_net}-subnet

