import unohelper
from com.sun.star.lang import XServiceName
from com.sun.star.lang import XMain
import string
import urllib.parse
import urllib.request
import base64

g_ImplementationHelper = unohelper.ImplementationHelper()

class receive( unohelper.Base, XServiceName, XMain ):
    def __init__( self, ctx ):
        self.output = "No output"
      
    def getServiceName( self ):
        return self.output
        
    def run( self, args ):
        url = args[0]
        username = args[1]
        password = args[2]
        nodeID = args[3]
        filename = args[4]

        # format the URL
        if url.find( 'http://' ) == -1:
            url = 'http://' + url
        if url[len( url ) - 1] != '/':
            url = url + '/'
        url = url + 'odf/upload_export'

        # send the request to the server
        try:
            # Python 3 (LibreOffice ships no Python 2): urllib.request, POST body as bytes;
            # the answer is base64, the decoded document binary
            params = urllib.parse.urlencode( { "Username": username, "Password": password, "NodeID": nodeID } ).encode( 'utf-8' )
            f = urllib.request.urlopen( url, params )
            output = f.read()
            if output.find( b'problem:' ) == 0:
                self.output = output.decode( 'utf-8', 'replace' )
            output = base64.standard_b64decode( output )
            try:
                fobject = open( filename, 'wb' )
                fobject.write( output )
                fobject.close( )
            except:
                self.output = 'problem:Couldn\'t write to local temporary file'
                self.output = filename
        except:
            outputList = []
            outputList.append( 'problem:Connection to the server impossible' )
            outputList.append( 'Possible causes are :' )
            outputList.append( '- bad server URL' )
            outputList.append( '- no connection to the server' )
            output = ""
            self.output = output.join( outputList )
        self.output = 'Done'
        return 1

g_ImplementationHelper.addImplementation( \
    receive, "org.openoffice.pyuno.eZsystems.receive",
    ( "com.sun.star.lang.XServiceName", "com.sun.star.lang.XMain" ), )
