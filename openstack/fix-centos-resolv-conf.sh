cat <<DNS > /etc/resolv.conf
search openstacklocal novalocal
nameserver 8.8.8.8
nameserver 1.1.1.1
options edns0
DNS
