#!/bin/bash
#
# Create a user on a remote host and optionally install their authorized keys
# file and sudo access. Note: This script assumes that the user running the script
# has password-less sudo access on the host.

function show_help {
cat <<HELP
Usage $0 -r *remote-host* -u *user-name* [-c *comment*] [-a *authorized-key-file*] [-s]
Where:
    -r Remote host
    -u Username to create
    -c Optional GCOS comment for user
    -a If set, copy the specified file into ~/.ssh/authorized_keys on the remote host
    -s If set, add this user to the sudoers file
HELP
}

user=
comment=
remote_host=
authorized_keys_file=
superuser=0
sudoers_file_path=/etc/sudoers.d/90-cloud-init-users

while getopts "h?r:u:c:a:s" opt; do
    case "$opt" in
        h|\?)
            show_help
            exit 0
            ;;
        a)  authorized_keys_file=$OPTARG
            ;;
        c)  comment=$OPTARG
            ;;
	r)  remote_host=$OPTARG
            ;;
        s)  superuser=1
            ;;
        u)  user=$OPTARG
            ;;
    esac
done

if [[ -z $remote_host || -z $user ]]; then
    show_help
    exit 1
fi

if [[ -n $authorized_keys_file && ! -f $authorized_keys_file ]]; then
    echo "Could not read authorized keys file: $authorized_keys_file"
    show_help
    exit 1
fi

# Build a script to do the work, then ship the optional authorized keys file and the script
# to the remote host and run it.

script=$(mktemp -p /tmp create_XXXXX)

if [[ ! -f $script ]]; then
    echo "Error creating temporary script file: $script"
    exit 1
else
    echo "Creating remote script: $script"
fi

# Pass variables

cat<<VARS >>$script
user=$user
comment="$comment"
authorized_keys_file=$authorized_keys_file
key_file_name=$(basename $authorized_keys_file)
sudoers_file_path=$sudoers_file_path

VARS

# Create the user

cat<<'USERADD' >>$script
echo "Create user $user"
msg=$(useradd  -c "$comment" -s /bin/bash -m $user 2>&1)

if [ 0 -ne $? ]; then
    echo "Error creatng $user: $msg"
    exit 1
fi
USERADD

# Optionally copy over the authorized_keys file

if [[ -n $authorized_keys_file ]]; then
    scp $authorized_keys_file $remote_host:/tmp/
    cat<<'KEYS' >>$script

echo "Install authorized_keys file"

msg=$(install -o $user -g $user -m 0750 -d /home/$user/.ssh 2>&1)
if [ 0 -ne $? ]; then
    echo "Error installing authorized_keys for $user: $msg"
    exit 1
fi

msg=$(install -o $user -g $user -m 0640 /tmp/$key_file_name /home/$user/.ssh/authorized_keys 2>&1)
if [ 0 -ne $? ]; then
    echo "Error installing authorized_keys for $user: $msg"
    exit 1
fi

rm -f /tmp/$key_file_name
KEYS
fi

# Optionally add a sudoers entry

if [ 1 -eq $superuser ]; then
    cat<<'SUDOERS' >>$script

echo "Add $user to sudoers"

sudoers_file=$(basename $sudoers_file_path)
cp $sudoers_file_path /tmp/$sudoers_file

cat<<SUDO >> /tmp/$sudoers_file

# User rules for $user
$user ALL=(ALL) NOPASSWD:ALL
SUDO

msg=$(visudo -c -f /tmp/$sudoers_file 2>&1)
if [ 0 -eq $? ]; then
    cp $sudoers_file_path ${sudoers_file_path}.bak
    cp /tmp/$sudoers_file $sudoers_file_path
    rm -f /tmp/$sudoers_file
else
    echo "WARNING: Error modifying sudoers file $sudoers_file"
    echo $msg
    echo
    cat -n /tmp/$sudoers_file
    rm -f /tmp/$sudoers_file
    exit 1
fi

SUDOERS
fi

cat<<'CLEANUP' >>$script

exit 0
CLEANUP

# Copy the script and supporting files to the remote host and run the script

scp $script $remote_host:/tmp/
ssh $remote_host "sudo bash $script && rm -f $script"

rm -f $script

exit 0
