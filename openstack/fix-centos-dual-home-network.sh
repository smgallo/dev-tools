# Fix issue with centos hosts on 2 networks where default routes are created for each
# interface. Only create the default route on eth0.

cat <<ETH0 >> /etc/sysconfig/network-scripts/ifcfg-eth0
DEFROUTE=yes
PEERDNS="no"
ETH0

cp /etc/sysconfig/network-scripts/ifcfg-eth0 /etc/sysconfig/network-scripts/ifcfg-eth1
sed -i -e 's/DEFROUTE=yes/DEFROUTE=no/' /etc/sysconfig/network-scripts/ifcfg-eth1
MAC_ADDR=$(ifconfig eth1 | awk '/ether/ { print $2; }')
sed -i -e "s/^HWADDR=.*/HWADDR=$MAC_ADDR/" -e "s/^DEVICE=.*/DEVICE=eth1/" /etc/sysconfig/network-scripts/ifcfg-eth1

cat <<EOF > /etc/cloud/cloud.cfg.d/centos_resolv_fix.cfg
manage_resolv_conf: false
EOF

